<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use DateTimeImmutable;
use LogicException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Placement;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comments on the draft, as conversation messages: what the addons call.
 *
 * Pins not sent yet are the editor's own, in their panel. **Apply** sends
 * them as one message from the editor (`comments.items`: each comment's
 * number, scope and words), claiming the piece as Send does; the job then
 * runs the scoped revision (one `reviser` call) and Ghostwriter answers
 * with one message (`comments.answers` naming the editor's message, and
 * `comments.results`: Changed with before and after, Replied, Refused or
 * Skipped, each with a reason). Put back and Resolve are noted on that
 * answer. Everything is in the conversation, so shared conversations (E7)
 * need nothing more: one run at a time, through the session's claim.
 *
 *     $session = $comments->apply($id, $viewer, [['scope' => Scope::block(['u4'], 'Visits list'), 'body' => 'Shorter.']]);
 *     $comments->revise($id, $conversation, $writer, $site);       // the job
 *     $comments->pins($session);                                    // every sent comment, with its state
 *     $comments->resolve($id, $viewer, $answerIndex, 1);
 */
final class Comments
{
    /** The key comment messages carry their items, or results, under. */
    public const KEY = 'comments';

    /** Comments sent in one Apply, at most. */
    public const PER_APPLY = 12;

    private readonly ReviewRules $rules;

    private readonly LoggerInterface $logger;

    private readonly ?SessionLayouts $sessionLayouts;

    /**
     * @param  Studio|null  $studio  For revise(). Sending, resolving and the pins need neither it nor `$sessionLayouts`.
     * @param  SessionLayouts|null  $sessionLayouts  The addon's, if it has one; made from the Studio otherwise.
     */
    public function __construct(
        private readonly SessionGuard $guard,
        private readonly ?Studio $studio = null,
        ?SessionLayouts $sessionLayouts = null,
        private readonly Layouts $layouts = new Layouts,
        ?LoggerInterface $logger = null,
    ) {
        $this->rules = new ReviewRules($guard->access());
        $this->logger = $logger ?? new NullLogger;
        $this->sessionLayouts = $sessionLayouts ?? ($studio !== null ? new SessionLayouts($studio, $layouts, $this->logger) : null);
    }

    public function rules(): ReviewRules
    {
        return $this->rules;
    }

    /** The number the next comment's pin takes: one past every comment sent on the piece. */
    public static function nextNumber(Session $session): int
    {
        $max = 0;

        foreach (self::sent($session) as $comments) {
            foreach ($comments as $comment) {
                $max = max($max, $comment->number);
            }
        }

        return $max + 1;
    }

    /**
     * **Apply N comments**: the editor's comments go into the conversation
     * as one message, and the piece is claimed for the run, as Send does
     * (someone else's run gives Busy: "Priya is waiting on Ghostwriter").
     * Numbers follow on from the comments already sent. Start the job that
     * calls revise() after.
     *
     * @param  list<array{scope: Scope, body: string}>  $comments
     *
     * @throws Conflict|Busy|NotAllowed|NotFound Conflict for none, too many, an empty one, or no draft.
     */
    public function apply(string $sessionId, Viewer $viewer, array $comments): Session
    {
        $comments = array_values(array_filter($comments, fn (array $comment) => trim($comment['body']) !== ''));

        if ($comments === []) {
            throw new Conflict('There are no comments to apply.');
        }

        if (count($comments) > self::PER_APPLY) {
            throw new Conflict('Apply at most '.self::PER_APPLY.' comments at a time.');
        }

        return $this->guard->begin($sessionId, $viewer, function (Session $session, DateTimeImmutable $now) use ($viewer, $comments) {
            if (! $this->rules->mayApply($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            if ($session->draft === null || trim($session->draft) === '') {
                throw new Conflict('There is no draft to comment on yet.');
            }

            $hashes = self::hashesNow($session);
            $number = self::nextNumber($session);
            $items = [];

            foreach ($comments as $comment) {
                // What it may change: its own units and items, or anything on the page.
                $editable = $comment['scope']->kind === ScopeKind::Page ? $hashes : array_intersect_key($hashes, array_flip($comment['scope']->units));
                $items[] = Comment::make($number++, $comment['scope'], $comment['body'], $viewer->id, $editable);
            }

            $session->addMessage('user', self::message($items), $viewer->id, [self::KEY => ['items' => array_map(fn (Comment $comment) => $comment->toArray(), $items)]], $now);
        }, 'Ghostwriter is working on this piece. Apply the comments when it has finished.');
    }

    /**
     * The run, in the job apply() started: one call to the reviser for
     * every comment in the editor's last message, checked
     * (RevisionValidator) and applied (RevisionApplier) under the session's
     * lock, skipping any unit someone changed meanwhile; then Ghostwriter's
     * answer. If the call fails, the answer says so for each comment and
     * nothing in the draft changes. Either way the piece is idle again.
     *
     * @param  array<int|string, string>  $names  User id => name, for "By Priya" in the prompt; optional.
     */
    public function revise(string $sessionId, Conversation $conversation, WriterContext $writer, LayoutContext $site, array $names = []): ApplyOutcome
    {
        if ($this->studio === null || $this->sessionLayouts === null) {
            throw new LogicException('Comments needs the Studio to apply comments.');
        }

        $session = $this->guard->change($sessionId, fn () => false);
        $run = $session === null ? null : self::unanswered($session);

        if ($session === null || $run === null || $session->draft === null) {
            return new ApplyOutcome;
        }

        $comments = self::itemsOf($session->messages[$run]);
        $sources = ExtraSources::fromWriter($conversation, $session->draft, $writer->layout);
        $units = Units::fromDraft(Draft::parse($session->draft)->data, $site->schema, $this->layouts->richText)->restore($session->units);
        $request = new RevisionRequest($comments, $units, Extras::fromArray($session->extras), $this->sessionLayouts->chosen($session), $writer, $conversation, $names);

        try {
            $result = $this->studio->revise($request);
        } catch (ProviderException $exception) {
            $this->logger->warning("Ghostwriter: the reviser failed: {$exception->getMessage()}", ['agent' => 'reviser']);
            $this->fail($sessionId, $exception->getMessage());

            return new ApplyOutcome(failed: $exception->getMessage());
        }

        $outcome = new ApplyOutcome(failed: 'The piece has gone.');
        $applier = new RevisionApplier($this->sessionLayouts, $this->layouts, $this->logger);
        $this->guard->change($sessionId, function (Session $session) use ($applier, $result, $site, $sources, $run, &$outcome) {
            if (self::unanswered($session) !== $run) {
                return false;
            }

            $outcome = $applier->apply($session, $run, self::itemsOf($session->messages[$run]), $result->value, $site, $sources, $result->usage);
        });

        return $outcome;
    }

    /**
     * The run couldn't happen (a provider error, or the job stopped): the
     * answer says why for each comment, nothing in the draft changes, and
     * the piece is idle again. Nothing to do when the comments were answered.
     */
    public function fail(string $sessionId, string $why): void
    {
        $why = rtrim(trim($why), '.').'.';

        $this->guard->change($sessionId, function (Session $session) use ($why) {
            $run = self::unanswered($session);

            if ($run === null) {
                return false;
            }

            $results = array_map(fn (Comment $comment) => (new CommentResult($comment->number, $comment->id, CommentOutcome::Failed, "I couldn’t revise this: {$why} Send it again to try once more."))->toArray(), self::itemsOf($session->messages[$run]));
            $session->addMessage('assistant', "I couldn’t apply the comments: {$why} Nothing changed; send them again to try once more.", null, [self::KEY => ['answers' => $run, 'results' => $results]]);
            $session->status = Session::IDLE;
            $session->error = null;
        });
    }

    /**
     * **Resolve** a comment Ghostwriter answered (or reopen it, with
     * `$resolved` false): noted on its result in the answer message.
     * Allowed while Ghostwriter works on something else.
     *
     * @param  int  $answer  The answer message's index.
     *
     * @throws NotFound|NotAllowed
     */
    public function resolve(string $sessionId, Viewer $viewer, int $answer, int $number, bool $resolved = true): Session
    {
        return $this->guard->annotate($sessionId, $viewer, function (Session $session) use ($viewer, $answer, $number, $resolved) {
            if (! $this->rules->mayComment($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            $result = self::result($session, $answer, $number);

            if (($result->resolved !== null) === $resolved) {
                return false;
            }

            self::store($session, $answer, $result->withResolved($viewer->id, $resolved ? (new DateTimeImmutable)->format(DATE_ATOM) : null));
        });
    }

    /**
     * **Put it back**: a comment's change undone, when its text is still
     * what Ghostwriter wrote (Conflict otherwise). Noted on its result; the
     * layouts follow. Refused while Ghostwriter works, as hand edits are.
     *
     * @throws Conflict|Busy
     */
    public function putBack(string $sessionId, Viewer $viewer, int $answer, int $number, LayoutContext $site): Session
    {
        if ($this->sessionLayouts === null) {
            throw new LogicException('Comments needs the Studio, or the addon’s SessionLayouts, to put a change back.');
        }

        return $this->guard->edit($sessionId, $viewer, function (Session $session) use ($viewer, $answer, $number, $site) {
            if (! $this->rules->mayComment($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            $result = self::result($session, $answer, $number);

            if (! $result->canPutBack()) {
                throw new Conflict('There is no change here to put back.');
            }

            $draft = Draft::parse((string) $session->draft);
            $data = $draft->data;
            $units = Units::fromDraft($data, $site->schema, $this->layouts->richText)->restore($session->units);
            $extras = Extras::fromArray($session->extras);
            $editor = new DraftEditor;

            foreach (array_filter($result->changes, fn (Change $change) => ! $change->layout) as $change) {
                $unit = $units->get($change->unit);
                $item = $extras->item($change->unit);
                $now = $unit->markdown ?? $item->text ?? null;

                if ($now === null || NormalisedText::string($now) !== NormalisedText::string($change->after)) {
                    throw new Conflict('This text has changed since, so nothing was put back.');
                }

                if ($unit !== null) {
                    $data = $editor->set($data, $unit, $change->before);
                } else {
                    $extras = $extras->edit($change->unit, $change->before);
                }
            }

            $session->draft = $editor->dump($data);
            $session->units = Units::fromDraft($data, $site->schema, $this->layouts->richText)->restore($units->sidecar())->sidecar();
            $session->extras = $extras->toArray();
            self::store($session, $answer, $result->withPutBack($viewer->id, (new DateTimeImmutable)->format(DATE_ATOM)));
            $this->sessionLayouts?->afterEdit($session, null, $site);
        });
    }

    /**
     * Every comment sent on the piece, by number, for the pins and the
     * chat: its message, scope and words, and its state worked out from
     * the conversation, placed in a layout (the chosen one by default).
     * Each has `number`, `id`, `message` (the editor's message's index),
     * `answer` (Ghostwriter's, or null), `scope`, `body`, `by`, `status`
     * (CommentStatus), `state` (its label), `outcome`, `reply`, `rules`,
     * `changes` (each with a word `diff`), `resolved`, `putBack`,
     * `canPutBack`, `blocks` (the block paths holding its units in the
     * layout), `inLayout` and `detached` (its words have gone).
     *
     * @return list<array<string, mixed>>
     */
    public function pins(Session $session, ?string $planId = null): array
    {
        $where = $this->where($session, $planId);
        $answers = self::answers($session);
        $unitIds = self::unitIds($session);
        $extraIds = array_keys(Extras::fromArray($session->extras)->items());
        $working = $session->isWorking();
        $out = [];

        foreach (self::sent($session) as $index => $comments) {
            $answer = $answers[$index] ?? null;

            foreach ($comments as $comment) {
                $result = $answer === null ? null : ($answer['results'][$comment->number] ?? null);
                $scope = $result?->quote !== null ? $comment->scope->withUnits($comment->scope->units, $result->quote) : $comment->scope;
                $detached = $unitIds !== null && $scope->kind !== ScopeKind::Page
                    && array_filter($scope->units, fn (string $id) => in_array($id, $unitIds, true) || in_array($id, $extraIds, true)) === [];
                $status = match (true) {
                    $result?->resolved !== null => CommentStatus::Resolved,
                    $result === null && ! $working && $answer === null => CommentStatus::Failed,
                    $detached => CommentStatus::Detached,
                    default => CommentStatus::of($result?->outcome),
                };
                $blocks = self::blocksFor($scope, $where);

                $out[] = [
                    'number' => $comment->number,
                    'id' => $comment->id,
                    'message' => $index,
                    'answer' => $answer['index'] ?? null,
                    'scope' => $scope->toArray(),
                    'body' => $comment->body,
                    'by' => $comment->by,
                    'status' => $status->value,
                    'state' => $status->label(),
                    'outcome' => $result?->outcome->value,
                    'reply' => $result?->reply,
                    'rules' => $result->rules ?? [],
                    'changes' => array_map(fn (Change $change) => [
                        'unit' => $change->unit,
                        'before' => $change->before,
                        'after' => $change->after,
                        'filled' => $change->filled,
                        'layout' => $change->layout,
                        'diff' => $change->layout ? [] : WordDiff::diff($change->before, $change->after),
                    ], $result->changes ?? []),
                    'resolved' => $result?->resolved,
                    'putBack' => $result?->putBack,
                    'canPutBack' => $result !== null && $result->canPutBack(),
                    'blocks' => $blocks,
                    'inLayout' => $scope->kind === ScopeKind::Page || $where === null || $blocks !== [],
                    'detached' => $detached,
                ];
            }
        }

        usort($out, fn (array $a, array $b) => $a['number'] <=> $b['number']);

        return $out;
    }

    /**
     * The block paths of a layout (the chosen one by default) with the
     * units and extra items in each, or null when the piece has no layouts.
     *
     * @return array<string, list<string>>|null
     */
    public function where(Session $session, ?string $planId = null): ?array
    {
        $plans = Plans::fromArray($session->plans);
        $plan = $planId !== null ? $plans->get($planId) : (($session->plan !== null ? $plans->get($session->plan) : null) ?? $plans->writer());

        return $plan === null ? null : self::blocksOf($plan);
    }

    /**
     * The index of the editor's comments message still waiting for its
     * answer, if any: the run going now (or one that stopped).
     */
    public static function unanswered(Session $session): ?int
    {
        $answered = [];
        $last = null;

        foreach ($session->messages as $index => $message) {
            if (($message['role'] ?? null) === 'assistant' && is_int($message[self::KEY]['answers'] ?? null)) {
                $answered[$message[self::KEY]['answers']] = true;
            }

            if (($message['role'] ?? null) === 'user' && is_array($message[self::KEY]['items'] ?? null)) {
                $last = $index;
            }
        }

        return $last !== null && ! isset($answered[$last]) ? $last : null;
    }

    /**
     * The comments in a message (the editor's comments message).
     *
     * @param  array<string, mixed>  $message
     * @return list<Comment>
     */
    public static function itemsOf(array $message): array
    {
        $items = [];

        foreach (is_array($message[self::KEY]['items'] ?? null) ? $message[self::KEY]['items'] : [] as $item) {
            if (is_array($item) && ($comment = Comment::fromArray($item)) !== null) {
                $items[] = $comment;
            }
        }

        return $items;
    }

    /**
     * The results in an answer message, by number.
     *
     * @param  array<string, mixed>  $message
     * @return array<int, CommentResult>
     */
    public static function resultsOf(array $message): array
    {
        $results = [];

        foreach (is_array($message[self::KEY]['results'] ?? null) ? $message[self::KEY]['results'] : [] as $item) {
            if (is_array($item) && ($result = CommentResult::fromArray($item)) !== null) {
                $results[$result->number] = $result;
            }
        }

        return $results;
    }

    /**
     * Block path => the units and extra items in it (refs without their
     * pieces or parts), in the layout's order: "page_builder/2",
     * "page_builder/2/cards/0", "body" for a rich-text or plain field.
     *
     * @return array<string, list<string>>
     */
    public static function blocksOf(Plan $plan): array
    {
        $out = [];

        foreach ($plan->fields as $handle => $blocks) {
            $flat = array_filter($blocks, fn (PlanBlock $block) => $block->type === 'value' || array_filter($block->placements, fn (Placement $placement) => $placement->field === Placement::BODY) !== []);

            if ($blocks !== [] && count($flat) === count($blocks)) {
                $refs = [];

                foreach ($blocks as $block) {
                    array_push($refs, ...self::ids($block->refs()));
                }

                $out[(string) $handle] = array_values(array_unique($refs));

                continue;
            }

            self::walk($blocks, (string) $handle, $out);
        }

        return $out;
    }

    /**
     * A comment message's text, as the writer reads the conversation later.
     *
     * @param  list<Comment>  $items
     */
    private static function message(array $items): string
    {
        $lines = array_map(fn (Comment $comment) => "{$comment->number}. ".match ($comment->scope->kind) {
            ScopeKind::Page => 'On the whole page: ',
            default => 'On “'.($comment->scope->label ?? 'a block').'”'.($comment->scope->quote !== null ? ' (“'.$comment->scope->quote->exact.'”)' : '').': ',
        }.$comment->body, $items);

        return (count($items) === 1 ? '1 comment on the draft:' : count($items).' comments on the draft:')."\n".implode("\n", $lines);
    }

    /**
     * The editor's comments messages: message index => their comments.
     *
     * @return array<int, list<Comment>>
     */
    private static function sent(Session $session): array
    {
        $out = [];

        foreach ($session->messages as $index => $message) {
            if (($message['role'] ?? null) === 'user' && is_array($message[self::KEY]['items'] ?? null)) {
                $out[$index] = self::itemsOf($message);
            }
        }

        return $out;
    }

    /**
     * Ghostwriter's answers: the editor's message index => the answer's index and results.
     *
     * @return array<int, array{index: int, results: array<int, CommentResult>}>
     */
    private static function answers(Session $session): array
    {
        $out = [];

        foreach ($session->messages as $index => $message) {
            if (($message['role'] ?? null) === 'assistant' && is_int($message[self::KEY]['answers'] ?? null)) {
                $out[$message[self::KEY]['answers']] = ['index' => $index, 'results' => self::resultsOf($message)];
            }
        }

        return $out;
    }

    private static function result(Session $session, int $answer, int $number): CommentResult
    {
        $message = $session->messages[$answer] ?? null;
        $result = is_array($message) && ($message['role'] ?? null) === 'assistant' ? (self::resultsOf($message)[$number] ?? null) : null;

        if ($result === null) {
            throw new NotFound('That comment has no answer from Ghostwriter.');
        }

        return $result;
    }

    private static function store(Session $session, int $answer, CommentResult $result): void
    {
        $message = $session->messages[$answer];
        $results = self::resultsOf($message);
        $results[$result->number] = $result;
        ksort($results);
        $message[self::KEY]['results'] = array_values(array_map(fn (CommentResult $result) => $result->toArray(), $results));
        $messages = $session->messages;
        $messages[$answer] = $message;
        $session->messages = $messages;
    }

    /**
     * The hashes of the draft's units and the extra items now.
     *
     * @return array<string, string>
     */
    private static function hashesNow(Session $session): array
    {
        $hashes = [];

        foreach (is_array($session->units['units'] ?? null) ? $session->units['units'] : [] as $id => $unit) {
            if (is_array($unit) && is_scalar($unit['hash'] ?? null)) {
                $hashes[(string) $id] = (string) $unit['hash'];
            }
        }

        return $hashes + RevisionApplier::hashes(Units::of([]), Extras::fromArray($session->extras));
    }

    /**
     * @param  array<string, list<string>>|null  $where
     * @return list<string>
     */
    private static function blocksFor(Scope $scope, ?array $where): array
    {
        $paths = [];

        foreach ($where ?? [] as $path => $refs) {
            if (array_intersect($scope->units, $refs) !== []) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @param  array<string, list<string>>  $out
     */
    private static function walk(array $blocks, string $path, array &$out): void
    {
        foreach ($blocks as $i => $block) {
            $at = "{$path}/{$i}";
            $own = [];

            foreach ($block->placements as $placement) {
                array_push($own, ...self::ids($placement->refs()));
            }

            $out[$at] = array_values(array_unique($own));

            foreach ($block->children as $field => $children) {
                self::walk($children, "{$at}/{$field}", $out);
            }
        }
    }

    /**
     * "u7" from "u7#2:lead", "x1.2" from "x1.2.question".
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    private static function ids(array $refs): array
    {
        return array_map(fn (string $ref) => str_starts_with($ref, 'x') ? Extras::itemId($ref) : explode('#', $ref)[0], $refs);
    }

    /**
     * The draft's unit ids now, from the session's sidecar; null when it has none.
     *
     * @return list<string>|null
     */
    private static function unitIds(Session $session): ?array
    {
        $units = $session->units['units'] ?? null;

        return is_array($units) && $units !== [] ? array_map('strval', array_keys($units)) : null;
    }
}
