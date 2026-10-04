<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Placement;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

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

    public function __construct(
        private readonly SessionGuard $guard,
    ) {
        $this->rules = new ReviewRules($guard->access());
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
