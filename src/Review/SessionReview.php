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
 * Comments on a piece: what the addons call. Every change goes through
 * SessionGuard under the session's lock, and is allowed while Ghostwriter
 * works (§9.5: new comments wait for the next Apply). The comments are on
 * the session (`review`), shared by everyone on it (E7).
 *
 *     $thread = $comments->add($id, $viewer, Scope::block(['u4'], 'Visits list', 'w', 'page_builder/2'), 'Make each visit one line.');
 *     $comments->reply($id, $viewer, $thread->id, 'And keep the months.');
 *     $comments->resolve($id, $viewer, $thread->id);
 *     $comments->threads($session);          // the sidebar: every thread with its state
 *
 * Each change takes the review's `version` as the panel last saw it, when
 * it has one: a change made from an older copy is refused (Conflict), so
 * the panel fetches the comments again first. Null skips the check.
 *
 * Refusals are SessionGuard's (NotFound, NotAllowed) and Conflict: a
 * thread being revised now, a limit reached, an empty comment, a stale
 * version.
 */
final class SessionReview
{
    private readonly ReviewRules $rules;

    private readonly LoggerInterface $logger;

    private readonly ?SessionLayouts $sessionLayouts;

    /**
     * @param  Studio|null  $studio  For Apply (revise()). Adding, replying, resolving and the list need neither it nor `$sessionLayouts`.
     * @param  SessionLayouts|null  $sessionLayouts  The addon's, if it has one; made from the Studio otherwise. Apply and Put it back re-arrange the layouts through it.
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

    /** The piece's comments. */
    public function review(Session $session): Review
    {
        return Review::fromArray($session->review);
    }

    /**
     * A new comment, Not sent.
     *
     * @throws NotAllowed|Conflict
     */
    public function add(string $sessionId, Viewer $viewer, Scope $scope, string $body, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, function (Review $review, Session $session) use ($viewer, $scope, $body) {
            if ($session->draft === null || trim($session->draft) === '') {
                throw new Conflict('There is no draft to comment on yet.');
            }

            return $review->add($scope, $body, $viewer, new DateTimeImmutable);
        });
    }

    /**
     * The comment's words changed by its author, before it is sent.
     *
     * @throws NotAllowed|Conflict
     */
    public function edit(string $sessionId, Viewer $viewer, string $threadId, string $body, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, function (Review $review, Session $session) use ($viewer, $threadId, $body) {
            if (! $this->rules->mayEdit($session, $review->find($threadId), $viewer)) {
                throw new NotAllowed('Only the person who wrote a comment can change it.');
            }

            return $review->edit($threadId, $body);
        });
    }

    /**
     * A comment and its thread deleted, by its author or a manager.
     *
     * @throws NotAllowed|Conflict
     */
    public function delete(string $sessionId, Viewer $viewer, string $threadId, ?int $version = null): void
    {
        $this->change($sessionId, $viewer, $version, function (Review $review, Session $session) use ($viewer, $threadId) {
            if (! $this->rules->mayDelete($session, $review->find($threadId), $viewer)) {
                throw new NotAllowed('Only the person who wrote a comment, or a manager, can delete it.');
            }

            $review->remove($threadId);

            return null;
        });
    }

    /**
     * A reply from the editor: the thread goes with the next Apply.
     *
     * @throws NotAllowed|Conflict
     */
    public function reply(string $sessionId, Viewer $viewer, string $threadId, string $body, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, fn (Review $review) => $review->reply($threadId, $body, $viewer, new DateTimeImmutable));
    }

    /**
     * @throws NotAllowed|Conflict
     */
    public function resolve(string $sessionId, Viewer $viewer, string $threadId, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, fn (Review $review) => $review->resolve($threadId, $viewer, new DateTimeImmutable));
    }

    /**
     * Resolved → Changed or Replied (Not sent if never answered; Detached
     * if its text has gone).
     *
     * @throws NotAllowed|Conflict
     */
    public function reopen(string $sessionId, Viewer $viewer, string $threadId, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, fn (Review $review, Session $session) => $review->reopen($threadId, self::unitIds($session)));
    }

    /**
     * A thread anchored again: "Pin to a block" on a Detached one. It goes
     * with the next Apply.
     *
     * @throws NotAllowed|Conflict
     */
    public function repin(string $sessionId, Viewer $viewer, string $threadId, Scope $scope, ?int $version = null): Thread
    {
        return $this->change($sessionId, $viewer, $version, fn (Review $review) => $review->repin($threadId, $scope));
    }

    /**
     * **Apply N comments**: claims the piece for a run, as Send does (one
     * run at a time; someone else's gives Busy: "Priya is waiting on
     * Ghostwriter"), and puts up to 12 Not sent threads into it, recording
     * each unit's hash. The chat gets the editor's line ("Applied 2
     * comments: …"). Start the job that calls revise() after.
     *
     * @throws Conflict|Busy Conflict when there is nothing to apply (and NotAllowed, NotFound as SessionGuard's).
     */
    public function apply(string $sessionId, Viewer $viewer, ?int $version = null): Session
    {
        return $this->guard->begin($sessionId, $viewer, function (Session $session, DateTimeImmutable $now) use ($viewer, $version) {
            if (! $this->rules->mayApply($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            $review = Review::fromArray($session->review);

            if ($version !== null && $version !== $review->version) {
                throw new Conflict('The comments have changed since you last saw them. Look again, then try.');
            }

            $hashes = $session->units['units'] ?? [];
            $hashes = is_array($hashes) ? array_map(fn ($unit) => is_array($unit) && is_scalar($unit['hash'] ?? null) ? (string) $unit['hash'] : '', $hashes) : [];
            $sent = $review->send(array_map('strval', $hashes) + RevisionApplier::hashes(Units::of([]), Extras::fromArray($session->extras)));

            if ($sent === []) {
                throw new Conflict('There are no comments to apply.');
            }

            $waiting = count($review->open());
            $lines = array_map(fn (Thread $thread) => "{$thread->number}. ".($thread->scope->label !== null ? "On “{$thread->scope->label}”: " : '').implode(' ', array_map(fn (Note $note) => $note->body, $thread->asks())), $sent);
            $session->addMessage('user', 'Applied '.count($sent).' '.(count($sent) === 1 ? 'comment' : 'comments').":\n".implode("\n", $lines), $viewer->id, ['review' => ['step' => 'apply', 'threads' => array_map(fn (Thread $thread) => $thread->number, $sent), 'waiting' => $waiting]], $now);
            $session->review = $review->toArray();
        }, 'Ghostwriter is working on this piece. Apply the comments when it has finished.');
    }

    /**
     * The run, in the job apply() started: one call to the reviser for
     * every thread in it, the reply checked (RevisionValidator) and applied
     * (RevisionApplier) under the session's lock, skipping any unit someone
     * changed meanwhile. Build the conversation and writer context as for a
     * writer's turn. If the call fails, every thread goes back to Not sent
     * with a line saying so, and the piece is idle again.
     *
     * @param  array<int|string, string>  $names  User id => name, for "By Priya" in the prompt; optional.
     */
    public function revise(string $sessionId, Conversation $conversation, WriterContext $writer, LayoutContext $site, array $names = []): ApplyOutcome
    {
        if ($this->studio === null || $this->sessionLayouts === null) {
            throw new LogicException('SessionReview needs the Studio to apply comments.');
        }

        $session = $this->guard->change($sessionId, fn () => false);
        $review = $session === null ? null : Review::fromArray($session->review);

        if ($session === null || $review === null || $review->sending() === [] || $session->draft === null) {
            return new ApplyOutcome;
        }

        $sources = ExtraSources::fromWriter($conversation, $session->draft, $writer->layout);
        $draft = Draft::parse($session->draft);
        $units = Units::fromDraft($draft->data, $site->schema, $this->layouts->richText)->restore($session->units);
        $request = new RevisionRequest($review->sending(), $units, Extras::fromArray($session->extras), $this->sessionLayouts->chosen($session), $writer, $conversation, $names);

        try {
            $result = $this->studio->revise($request);
        } catch (ProviderException $exception) {
            $this->logger->warning("Ghostwriter: the reviser failed: {$exception->getMessage()}", ['agent' => 'reviser']);
            $message = $exception->getMessage();
            $this->guard->change($sessionId, function (Session $session) use ($message) {
                $review = Review::fromArray($session->review);

                foreach ($review->sending() as $thread) {
                    $review->sendBack($thread->id, "I couldn’t revise this: {$message} Apply again to try once more.");
                }

                $session->review = $review->toArray();
                $session->addMessage('assistant', "I couldn’t apply the comments: {$message} They’re back in the list to send again.", null, ['review' => ['step' => 'failed']]);
                $session->status = Session::IDLE;
                $session->error = null;
            });

            return new ApplyOutcome(failed: $message);
        }

        $outcome = new ApplyOutcome(failed: 'The piece has gone.');
        $applier = new RevisionApplier($this->sessionLayouts, $this->layouts, $this->logger);
        $this->guard->change($sessionId, function (Session $session) use ($applier, $result, $site, $sources, &$outcome) {
            $outcome = $applier->apply($session, $result->value, $site, $sources, $result->usage);
        });

        return $outcome;
    }

    /**
     * **Put it back**: the thread's last change undone, through the same
     * hash check: refused (Conflict) when the text has changed since. It
     * adds a line ("Put back.") and keeps the thread's state. Refused while
     * Ghostwriter works, as hand edits are. No model.
     *
     * @throws Conflict|Busy (and NotAllowed, NotFound as SessionGuard's)
     */
    public function putBack(string $sessionId, Viewer $viewer, string $threadId, LayoutContext $site): Thread
    {
        if ($this->sessionLayouts === null) {
            throw new LogicException('SessionReview needs the Studio, or the addon’s SessionLayouts, to put a change back.');
        }

        $result = null;

        $this->guard->edit($sessionId, $viewer, function (Session $session) use ($viewer, $threadId, $site, &$result) {
            if (! $this->rules->mayComment($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            $review = Review::fromArray($session->review);
            $thread = $review->find($threadId);
            $answer = $thread->lastAnswer();
            $changes = $answer === null ? [] : array_values(array_filter($answer->changes, fn (Change $change) => ! $change->layout));

            if ($changes === [] || array_filter($changes, fn (Change $change) => ! $change->canPutBack()) !== []) {
                throw new Conflict('There is no change here to put back.');
            }

            $draft = Draft::parse((string) $session->draft);
            $data = $draft->data;
            $units = Units::fromDraft($data, $site->schema, $this->layouts->richText)->restore($session->units);
            $extras = Extras::fromArray($session->extras);
            $editor = new DraftEditor;

            foreach ($changes as $change) {
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

            $review->remark($threadId, 'Put back.', $viewer->id);
            $session->draft = $editor->dump($data);
            $session->units = Units::fromDraft($data, $site->schema, $this->layouts->richText)->restore($units->sidecar())->sidecar();
            $session->extras = $extras->toArray();
            $session->review = $review->toArray();
            $this->sessionLayouts?->afterEdit($session, null, $site);
            $result = Review::fromArray($session->review)->find($threadId);
        });

        /** @var Thread $result */
        return $result;
    }

    /**
     * **Before / after** for a thread: each change Ghostwriter made for it,
     * latest run last, with a word diff (WordDiff: runs of `=`, `-`, `+`).
     *
     * @return list<array{unit: string, before: string, after: string, version: int, filled: list<array{ask: string, value: string, by: int|string|null}>, layout: bool, canPutBack: bool, diff: list<array{0: string, 1: string}>}>
     */
    public function changes(Session $session, string $threadId): array
    {
        return array_map(fn (Change $change) => [
            'unit' => $change->unit,
            'before' => $change->before,
            'after' => $change->after,
            'version' => $change->version,
            'filled' => $change->filled,
            'layout' => $change->layout,
            'canPutBack' => $change->canPutBack(),
            'diff' => $change->layout ? [] : WordDiff::diff($change->before, $change->after),
        ], $this->review($session)->find($threadId)->changes());
    }

    /**
     * The draft data with a thread's last change shown as it was before:
     * what the preview renders, read-only, for "Show before". No model,
     * nothing saved.
     *
     * @return array<string, mixed>
     */
    public function beforeData(Session $session, string $threadId, LayoutContext $site): array
    {
        $draft = Draft::parse((string) $session->draft);
        $data = $draft->data;
        $units = Units::fromDraft($data, $site->schema, $this->layouts->richText)->restore($session->units);
        $answer = $this->review($session)->find($threadId)->lastAnswer();
        $editor = new DraftEditor;

        foreach ($answer === null ? [] : $answer->changes as $change) {
            $unit = $units->get($change->unit);

            if ($unit !== null && ! $change->layout && $change->canPutBack()) {
                $data = $editor->set($data, $unit, $change->before);
            }
        }

        return $data;
    }

    /**
     * The sidebar: every thread, by number, with its state and where it
     * sits in a layout (the chosen one by default). Each is the stored
     * thread (Thread::toArray()) plus:
     * - `state`: "Not sent", "Revising", "Changed", "Replied", "Resolved", "Detached";
     * - `blocks`: the block paths in that layout holding its units ("page_builder/2"), first first;
     * - `inLayout`: false when none of its text is in that layout ("Not in this layout");
     * - `canPutBack`: its last change can be put back.
     *
     * @return list<array<string, mixed>>
     */
    public function threads(Session $session, ?string $planId = null): array
    {
        $where = $this->where($session, $planId);
        $threads = $this->review($session)->all();
        usort($threads, fn (Thread $a, Thread $b) => $a->number <=> $b->number);

        return array_map(function (Thread $thread) use ($where) {
            $answer = $thread->lastAnswer();
            $changes = $answer === null ? [] : $answer->changes;

            return $thread->toArray() + [
                'state' => $thread->status->label(),
                'blocks' => $where[$thread->id] ?? [],
                'inLayout' => $thread->scope->kind === ScopeKind::Page || ($where[$thread->id] ?? []) !== [],
                'canPutBack' => $changes !== [] && array_filter($changes, fn (Change $change) => ! $change->canPutBack()) === [],
            ];
        }, $threads);
    }

    /**
     * Where each thread's units are in a layout (the chosen one by
     * default): thread id => the paths of the blocks holding them, in the
     * layout's order ("page_builder/2", "page_builder/3/children/0",
     * "body" for a rich-text or plain field). Empty for a thread whose text
     * that layout doesn't use, and for a comment on the page. No model.
     *
     * @return array<string, list<string>>
     */
    public function where(Session $session, ?string $planId = null): array
    {
        $plans = Plans::fromArray($session->plans);
        $plan = $planId !== null ? $plans->get($planId) : (($session->plan !== null ? $plans->get($session->plan) : null) ?? $plans->writer());

        if ($plan === null) {
            return [];
        }

        $blocks = self::blocksOf($plan);
        $where = [];

        foreach ($this->review($session)->all() as $thread) {
            $paths = [];

            foreach ($blocks as $path => $refs) {
                if (array_intersect($thread->scope->units, $refs) !== []) {
                    $paths[] = $path;
                }
            }

            $where[$thread->id] = $paths;
        }

        return $where;
    }

    /**
     * Block path => the units and extra items in it (refs without their
     * pieces or parts), in the layout's order.
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
     * The draft's unit ids now, from the session's sidecar.
     *
     * @return list<string>|null
     */
    private static function unitIds(Session $session): ?array
    {
        $units = $session->units['units'] ?? null;

        return is_array($units) ? array_map('strval', array_keys($units)) : null;
    }

    /**
     * @template T
     *
     * @param  callable(Review, Session): T  $change
     * @return T
     */
    private function change(string $sessionId, Viewer $viewer, ?int $version, callable $change): mixed
    {
        $result = null;

        $this->guard->annotate($sessionId, $viewer, function (Session $session) use ($viewer, $version, $change, &$result) {
            if (! $this->rules->mayComment($session, $viewer)) {
                throw new NotAllowed('This piece is someone else’s.');
            }

            $review = Review::fromArray($session->review);

            if ($version !== null && $version !== $review->version) {
                throw new Conflict('The comments have changed since you last saw them. Look again, then try.');
            }

            $result = $change($review, $session);
            $session->review = $review->toArray();
        });

        /** @var T $result */
        return $result;
    }
}
