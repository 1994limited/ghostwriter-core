<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanOrigin;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Applies the reviser's reply to the session, under its lock, with no
 * model (§6.5). For each comment in the run (an editor's comments message),
 * in number order:
 *
 * 1. **Conflicts.** Each unit (or extra item) it touches is compared with
 *    the hash recorded when it was sent; one someone changed meanwhile is
 *    skipped, and its result says so. So is a unit an earlier comment in
 *    the same run rewrote whole.
 * 2. **Checks** (RevisionValidator). A comment that breaks a rule keeps the
 *    draft as it was and is Refused, with a plain reason.
 * 3. **The draft and units.** What passes is written with Text\DraftEditor,
 *    unit ids kept by place, extras edited; then SessionLayouts::afterEdit()
 *    re-arranges the layouts (no call).
 * 4. **A new arrangement** a comment asked for replaces its block in the
 *    chosen layout when it passes the layout rules (the writer's own
 *    layout is the draft, so the draft is re-arranged); otherwise the
 *    reply says the layout was kept.
 * 5. **The answer.** One assistant message, `comments.answers` naming the
 *    editor's message, with a CommentResult per comment: Changed (a Change
 *    per unit, before and after), Replied, Refused or Skipped.
 *
 * The run's tokens go on the session's usage, and the piece is idle again.
 */
final class RevisionApplier
{
    public const CONFLICT = 'Someone changed this block while I was working, so I changed nothing. Apply again to use the new version.';

    public const SAME_RUN = 'Another comment in this round rewrote the same text, so I changed nothing for this one. Apply again to use the new version.';

    public const UNREADABLE = 'The draft couldn’t be read, so I changed nothing.';

    public const REFUSED = [
        RevisionValidator::SCOPE => 'I couldn’t make this change without touching other parts of the page. Try commenting on the whole section.',
        RevisionValidator::TEXT_RANGE => 'I couldn’t make this change within the words you picked. Try commenting on the whole block.',
        RevisionValidator::MARKERS => 'I couldn’t make this change without losing or making up a gap left for you to fill, or a link to choose. Nothing was changed.',
        RevisionValidator::LINK => 'That change added a link to another site, so I didn’t make it.',
        RevisionValidator::FACTS => 'That change needed a fact I don’t have, so I didn’t make it.',
        RevisionValidator::LOST => 'That change would have merged, split or removed part of the page, so I didn’t make it. Try commenting on the whole section.',
        RevisionValidator::SHAPE => 'I couldn’t fit that change into this block’s fields.',
        RevisionValidator::MISSING => 'I didn’t get to this comment. Apply again to send it.',
    ];

    private readonly DraftEditor $editor;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SessionLayouts $sessionLayouts,
        private readonly Layouts $layouts = new Layouts,
        ?LoggerInterface $logger = null,
    ) {
        $this->editor = new DraftEditor;
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Applies the reply to the comments of the message at `$run`. Call it
     * with the session read afresh under its lock (SessionGuard::change()).
     *
     * @param  list<Comment>  $comments
     */
    public function apply(Session $session, int $run, array $comments, RevisionReply $reply, LayoutContext $site, ExtraSources $sources, Usage $usage = new Usage, ?DateTimeInterface $now = null): ApplyOutcome
    {
        $now ??= new DateTimeImmutable;

        try {
            $draft = Draft::parse((string) $session->draft);
        } catch (Throwable) {
            $draft = null;
        }

        if ($comments === [] || $draft === null) {
            $results = array_map(fn (Comment $comment) => new CommentResult($comment->number, $comment->id, CommentOutcome::Failed, self::UNREADABLE), $comments);

            return $this->finish($session, $run, $results, $usage, new ApplyOutcome(failed: $comments === [] ? null : 'The draft couldn’t be read.', usage: $usage, summary: 'I couldn’t apply the comments: the draft couldn’t be read. Nothing changed.'), $now);
        }

        $schema = $site->schema;
        $data = $draft->data;
        $units = $this->units($data, $schema, $session->units);
        $extras = Extras::fromArray($session->extras);
        $started = $this->hashes($units, $extras);
        $validator = new RevisionValidator($schema, $this->layouts->builder());
        $brief = $sources->brief;
        $other = array_values(array_filter([$sources->draft, ...array_column($sources->entries, 'text'), ...$sources->conversation], fn (string $text) => trim($text) !== ''));
        $rewritten = [];
        $answers = [];
        $results = [];
        $refused = [];
        $conflicted = [];
        $touched = [];

        foreach ($comments as $comment) {
            $item = $reply->item($comment->number);
            $conflict = $this->conflict($comment, $item, $started, $rewritten);

            if ($conflict !== null) {
                $results[$comment->number] = new CommentResult($comment->number, $comment->id, CommentOutcome::Skipped, $conflict);
                $conflicted[] = $comment->number;

                continue;
            }

            $verdict = $validator->check($comment, $item, $data, $units, $extras, $brief, $other);

            if (! $verdict->passes()) {
                $results[$comment->number] = new CommentResult($comment->number, $comment->id, CommentOutcome::Refused, self::refusal($verdict), rules: $verdict->rules);
                $refused[$comment->number] = $verdict->rules;
                $this->logger->info("Ghostwriter: comment {$comment->number} was not applied (".implode(', ', $verdict->rules).').', ['agent' => 'reviser', 'rules' => $verdict->rules]);

                continue;
            }

            $changes = [];
            $quote = null;

            foreach ($verdict->units as $id => $after) {
                $before = $units->get($id)->markdown ?? '';

                if ($after === $before) {
                    continue;
                }

                $changes[] = new Change($id, $before, $after, $run, $verdict->filled[$id] ?? []);
                $rewritten[$id] = isset($item->units[$id]) || isset($rewritten[$id]);
                $touched[$id] = true;

                if ($comment->scope->kind === ScopeKind::Text && $comment->scope->units === [$id]) {
                    $quote = self::requoted($comment->scope, $before, $after)->quote;
                }
            }

            foreach ($verdict->extras as $id => $change) {
                $old = $extras->item($id);
                $before = $old === null ? '' : $old->text;
                $extras = $change === null ? $extras->without($id) : $extras->edit($id, $change['text'], $change['parts'] === [] ? null : $change['parts'] + ($old === null ? [] : $old->parts));
                $changes[] = new Change($id, $before, $change['text'] ?? '', $run, $verdict->filled[$id] ?? [], cut: $change === null);
                $rewritten[$id] = true;
                $touched[$id] = true;
            }

            if ($verdict->data !== null && $verdict->units !== []) {
                $data = $verdict->data;
                $units = $this->units($data, $schema, $units->sidecar());
            }

            $answers[$comment->number] = ['comment' => $comment, 'verdict' => $verdict, 'changes' => $changes, 'notes' => self::notes($verdict), 'quote' => $quote];
        }

        if ($touched !== []) {
            $session->draft = $this->editor->dump($data);
            $session->units = $units->sidecar();
            $session->extras = $extras->toArray();
            $this->sessionLayouts->afterEdit($session, null, $site);
        }

        $laidOut = [];

        foreach ($answers as $number => $answer) {
            $verdict = $answer['verdict'];

            if ($verdict->layout === null) {
                continue;
            }

            $why = null;

            if ($this->layOut($session, $verdict->layout, $answer['comment']->scope, $site, $validator, $why)) {
                $laidOut[] = $number;
                $answers[$number]['changes'][] = new Change('@layout', '', '', $run, layout: true, cut: true);
            } else {
                $answers[$number]['notes'][] = 'I kept the layout'.($why !== null ? ": {$why}" : '').'.';
                $this->logger->info("Ghostwriter: comment {$number}'s new arrangement was not used ({$why}).", ['agent' => 'reviser']);
            }
        }

        $changed = [];
        $replied = [];

        foreach ($answers as $number => $answer) {
            $body = trim(implode(' ', array_filter([$answer['verdict']->reply !== '' ? $answer['verdict']->reply : ($answer['changes'] === [] ? 'I left this as it is.' : 'Done.'), ...$answer['notes']])));
            $outcome = $answer['changes'] === [] ? CommentOutcome::Replied : CommentOutcome::Changed;
            $results[$number] = new CommentResult($number, $answer['comment']->id, $outcome, $body, $answer['changes'], quote: $answer['quote']);
            $outcome === CommentOutcome::Changed ? $changed[] = $number : $replied[] = $number;
        }

        ksort($results);
        sort($changed);
        sort($replied);

        $outcome = new ApplyOutcome($changed, $replied, $refused, $conflicted, $laidOut, array_keys($touched), self::summary($comments, $changed, $replied, count($refused) + count($conflicted)), null, $usage);

        return $this->finish($session, $run, array_values($results), $usage, $outcome, $now);
    }

    /**
     * Each touched unit's or extra item's hash now, as Comments::apply() records them.
     *
     * @return array<string, string>
     */
    public static function hashes(Units $units, Extras $extras): array
    {
        $hashes = [];

        foreach ($units->all() as $unit) {
            $hashes[$unit->id] = $unit->hash();
        }

        foreach ($extras->items() as $id => $item) {
            $hashes[$id] = substr(sha1($item->text."\n".implode("\n", $item->parts)), 0, 16);
        }

        return $hashes;
    }

    /**
     * Why a comment can't be applied now: someone changed what it touches
     * since the run started, or an earlier comment rewrote it whole.
     *
     * @param  array<string, string>  $now
     * @param  array<string, bool>  $rewritten
     */
    private function conflict(Comment $comment, ?RevisionItem $item, array $now, array $rewritten): ?string
    {
        if ($item === null) {
            return null;
        }

        foreach ($item->touched() as $id) {
            if (isset($comment->hashes[$id]) && ($now[$id] ?? null) !== $comment->hashes[$id]) {
                return self::CONFLICT;
            }

            if (isset($rewritten[$id]) && (isset($item->units[$id]) || $rewritten[$id] || array_key_exists($id, $item->extras))) {
                return self::SAME_RUN;
            }
        }

        return null;
    }

    /**
     * A new arrangement put in the chosen layout: the writer's is the
     * draft, re-arranged; another is replaced in the session's layouts.
     *
     * @param  list<mixed>  $blocks
     */
    private function layOut(Session $session, array $blocks, Scope $scope, LayoutContext $site, RevisionValidator $validator, ?string &$why): bool
    {
        $chosen = $this->sessionLayouts->chosen($session);
        $draft = Draft::parse((string) $session->draft);
        $units = $this->units($draft->data, $site->schema, $session->units);
        $extras = Extras::fromArray($session->extras);

        if ($chosen === null) {
            $why = 'this page has no layout to change';

            return false;
        }

        $plan = $validator->layout($chosen, $blocks, $scope, $units, $extras, $draft->data, $site->pattern, $why);

        if ($plan === null) {
            return false;
        }

        if ($chosen->origin === PlanOrigin::Writer) {
            $before = $session->draft;
            $session->draft = $this->editor->dump((new Arranger)->arrange($plan, $units, $extras, $draft->data, $site->schema));
            $this->sessionLayouts->afterEdit($session, $before, $site);
        } else {
            $session->plans = Plans::fromArray($session->plans)->with($plan->with(stale: false))->toArray();
        }

        return true;
    }

    /**
     * @param  list<CommentResult>  $results
     */
    private function finish(Session $session, int $run, array $results, Usage $usage, ApplyOutcome $outcome, DateTimeInterface $now): ApplyOutcome
    {
        $session->addMessage('assistant', $outcome->summary !== '' ? $outcome->summary : 'Nothing changed.', null, [Comments::KEY => ['answers' => $run, 'results' => array_map(fn (CommentResult $result) => $result->toArray(), $results)]], $now);
        $session->usage = ['input' => (int) ($session->usage['input'] ?? 0) + $usage->input, 'output' => (int) ($session->usage['output'] ?? 0) + $usage->output] + $session->usage;
        $session->status = Session::IDLE;
        $session->error = null;

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<mixed>  $sidecar
     */
    private function units(array $data, Schema $schema, array $sidecar): Units
    {
        return Units::fromDraft($data, $schema, $this->layouts->richText)->restore($sidecar);
    }

    /** The scope's quote moved to the words that took its place; a block scope when nothing did. */
    private static function requoted(Scope $scope, string $before, string $after): Scope
    {
        $head = 0;
        $max = min(mb_strlen($before), mb_strlen($after));

        while ($head < $max && mb_substr($before, $head, 1) === mb_substr($after, $head, 1)) {
            $head++;
        }

        $tail = 0;

        while ($tail < $max - $head && mb_substr($before, -$tail - 1, 1) === mb_substr($after, -$tail - 1, 1)) {
            $tail++;
        }

        $length = mb_strlen($after) - $head - $tail;

        if ($length <= 0 || trim(mb_substr($after, $head, $length)) === '') {
            return Scope::block($scope->units, $scope->label, $scope->planId, $scope->blockPath);
        }

        [$start, $span] = Sentences::covering($after, $head, $length);

        return $scope->withUnits($scope->units, TextQuote::around($after, $start, $span));
    }

    /**
     * What goes after the reviser's reply: an ask filled from the editor's
     * words, and a length worth mentioning.
     *
     * @return list<string>
     */
    private static function notes(Verdict $verdict): array
    {
        $notes = [];
        $values = [];

        foreach ($verdict->filled as $fills) {
            foreach ($fills as $fill) {
                $values[] = '“'.$fill['value'].'”';
            }
        }

        if ($values !== []) {
            $notes[] = 'Filled in from your comment: '.implode(', ', $values).'.';
        }

        if (in_array(RevisionValidator::SIZE, $verdict->warnings, true)) {
            $notes[] = 'It came out a good deal shorter or longer than before; say if you want it nearer the old length.';
        }

        return $notes;
    }

    private static function refusal(Verdict $verdict): string
    {
        $rule = $verdict->rules[0] ?? RevisionValidator::MISSING;
        $line = self::REFUSED[$rule] ?? self::REFUSED[RevisionValidator::SCOPE];

        if ($rule === RevisionValidator::FACTS && $verdict->unsourced !== []) {
            $line = 'That change needed something I don’t have ('.implode(', ', array_slice($verdict->unsourced, 0, 3)).'). Say it in a new comment and apply again.';
        }

        return $line;
    }

    /**
     * The chat's line for the run.
     *
     * @param  list<Comment>  $comments
     * @param  list<int>  $changed
     * @param  list<int>  $replied
     */
    private static function summary(array $comments, array $changed, array $replied, int $notApplied): string
    {
        $label = function (int $number) use ($comments): string {
            foreach ($comments as $comment) {
                if ($comment->number === $number) {
                    return $comment->scope->label ?? ($comment->scope->kind === ScopeKind::Page ? 'the page' : "comment {$number}");
                }
            }

            return "comment {$number}";
        };
        $lines = [];

        if ($changed !== []) {
            $lines[] = 'Revised '.count($changed).' '.(count($changed) === 1 ? 'block' : 'blocks').' from your comments: '.implode(', ', array_unique(array_map($label, $changed))).'. Nothing else changed.';
        }

        if ($replied !== []) {
            $lines[] = 'Replied to '.count($replied).' '.(count($replied) === 1 ? 'comment' : 'comments').' without changing anything.';
        }

        if ($notApplied > 0) {
            $lines[] = $notApplied.' '.($notApplied === 1 ? 'comment' : 'comments').' couldn’t be applied; '.($notApplied === 1 ? 'its' : 'their').' reason is with '.($notApplied === 1 ? 'it' : 'each').'.';
        }

        return $lines === [] ? 'Nothing changed.' : implode(' ', $lines);
    }
}
