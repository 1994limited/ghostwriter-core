<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Planning;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedIdea;

/**
 * The content plan's rules, over a PlanStore:
 *
 * - Suggestions wait to be reviewed (E3). Closing the review keeps them;
 *   a new batch joins the one waiting. Only keep() and drop() end a batch.
 * - keep() adds every suggestion: those chosen as open ideas, the rest as
 *   dismissed, so they aren't suggested again. drop() throws the batch
 *   away without remembering it.
 * - A title already on the plan (in any state) or waiting in the batch is
 *   not suggested twice.
 * - Only a dismissed idea can be put back (E8).
 * - An idea whose piece was deleted is open again.
 * - Only open or dismissed ideas are cleared in bulk; a started piece goes
 *   with its conversation.
 *
 * Changes to the plan state are made under a lock, as a job and a request
 * can both be at it.
 */
final class Plan
{
    private const LOCK = 'plan';

    public function __construct(
        private readonly PlanStore $store,
        private readonly Lock $lock,
        private readonly Format $format,
    ) {}

    /**
     * Every idea, with any whose piece has gone open again.
     *
     * @param  callable(int|string): bool  $sessionExists  Whether the piece an idea started is still there.
     * @return array<int, Idea>
     */
    public function ideas(callable $sessionExists): array
    {
        $ideas = [];

        foreach ($this->store->ideas() as $idea) {
            if (self::release($idea, $sessionExists)) {
                $idea = $this->store->save($idea);
            }

            $ideas[] = $idea;
        }

        return $ideas;
    }

    /**
     * Open ideas, newest first, grouped by collection (E4).
     *
     * @param  array<int, Idea>  $ideas
     * @return array<string, array<int, Idea>>
     */
    public static function openByGroup(array $ideas): array
    {
        $groups = [];

        foreach (array_reverse($ideas) as $idea) {
            if ($idea->isOpen()) {
                $groups[$idea->group][] = $idea;
            }
        }

        return $groups;
    }

    /**
     * An idea added by hand.
     *
     * @param  array<string, mixed>  $idea  `title`, the group, `type`/`kind`, `why`, `notes`.
     */
    public function add(array $idea, ?DateTimeInterface $now = null): Idea
    {
        return $this->store->save(Idea::make($this->format, $idea, Idea::ADDED, $now));
    }

    /**
     * Start looking for ideas.
     *
     * @throws Conflict when Ghostwriter already is
     */
    public function begin(): PlanState
    {
        return $this->changeState(fn (PlanState $state) => $state->begin('suggest', 'Ghostwriter is already looking for ideas.'));
    }

    /**
     * The planner's suggestions, held for review alongside any batch still
     * waiting, without titles already planned or waiting.
     *
     * @param  array<int, SuggestedIdea|array<string, mixed>>  $suggestions
     * @return int How many joined the batch.
     */
    public function receive(array $suggestions): int
    {
        $added = 0;

        $this->changeState(function (PlanState $state) use ($suggestions, &$added) {
            $known = array_map(fn (Idea $idea) => $idea->key(), $this->store->ideas());

            foreach ($state->pending as $pending) {
                $known[] = Idea::titleKey(is_scalar($pending['title'] ?? null) ? (string) $pending['title'] : '');
            }

            foreach ($suggestions as $suggestion) {
                $item = $suggestion instanceof SuggestedIdea ? $suggestion->toArray($this->format->groupKey(), $this->format === Format::Filament ? 'kind' : 'type') : $suggestion;
                $key = Idea::titleKey(is_scalar($item['title'] ?? null) ? (string) $item['title'] : '');

                if ($key === '' || in_array($key, $known, true)) {
                    continue;
                }

                $known[] = $key;
                $state->pending[] = $item;
                $added++;
            }

            $state->succeed();
        });

        return $added;
    }

    public function failed(string $error): void
    {
        $this->changeState(fn (PlanState $state) => $state->fail($error));
    }

    /**
     * Keep the suggestions chosen, by their place in the batch, as open
     * ideas; the rest join the plan as dismissed. The batch is done.
     *
     * @param  array<int, int|string>  $chosen
     * @return array<int, Idea> The ideas kept open.
     */
    public function keep(array $chosen, ?DateTimeInterface $now = null): array
    {
        $chosen = array_map('intval', $chosen);
        $kept = [];
        $now ??= new DateTimeImmutable;

        $this->changeState(function (PlanState $state) use ($chosen, &$kept, $now) {
            foreach ($state->pending as $index => $suggestion) {
                $idea = Idea::make($this->format, $suggestion, Idea::SUGGESTED, $now);

                if (in_array($index, $chosen, true)) {
                    $kept[] = $this->store->save($idea);
                } else {
                    $idea->status = Idea::DISMISSED;
                    $this->store->save($idea);
                }
            }

            $state->pending = [];
        });

        return $kept;
    }

    /**
     * Throw the batch away, without adding it or remembering it as
     * dismissed: it may be suggested again another time.
     */
    public function drop(): void
    {
        $this->changeState(function (PlanState $state) {
            $state->pending = [];
        });
    }

    /**
     * A piece was started from the idea: it is in hand now.
     */
    public function start(int|string $id, int|string $session): Idea
    {
        $idea = $this->find($id);
        $idea->status = Idea::DRAFTED;
        $idea->session = $session;

        return $this->store->save($idea);
    }

    public function dismiss(int|string $id): Idea
    {
        $idea = $this->find($id);
        $idea->status = Idea::DISMISSED;

        return $this->store->save($idea);
    }

    /**
     * An idea back on the plan: a dismissed one (E8), or a started piece
     * that isn't finished, which "Back to ideas" gives up on (E5). A
     * finished piece can't be: that would plan something already written.
     *
     * Whether a started piece is finished depends on the host's record
     * (E6), so the host says: `$finished` is called with the idea. Without
     * it, a started piece is refused, so nothing finished slips through.
     *
     * @param  (callable(Idea): bool)|null  $finished
     *
     * @throws Conflict for an open idea, or a started one that is (or may be) finished
     */
    public function putBack(int|string $id, ?callable $finished = null): Idea
    {
        $idea = $this->find($id);

        $unfinished = $idea->isDrafted() && $finished !== null && ! $finished($idea);

        if (! $idea->isDismissed() && ! $unfinished) {
            throw new Conflict($idea->isDrafted()
                ? 'A finished piece can\'t be put back on the plan.'
                : 'Only a dismissed idea, or a piece that isn\'t finished, can be put back.');
        }

        $idea->status = Idea::OPEN;
        $idea->session = null;

        return $this->store->save($idea);
    }

    /**
     * Change an idea's words: title, group, kind, why, notes.
     *
     * @param  array<string, mixed>  $changes
     */
    public function edit(int|string $id, array $changes): Idea
    {
        $idea = $this->find($id);

        $text = fn (string $key, string $was): string => array_key_exists($key, $changes) ? trim(is_scalar($changes[$key]) ? (string) $changes[$key] : '') : $was;

        $idea->title = $text('title', $idea->title);
        $idea->why = $text('why', $idea->why);
        $idea->notes = $text('notes', $idea->notes);

        foreach ([$this->format->groupKey(), 'group'] as $key) {
            if (is_scalar($changes[$key] ?? null) && (string) $changes[$key] !== '') {
                $idea->group = (string) $changes[$key];
            }
        }

        foreach (['type', 'kind'] as $key) {
            if (array_key_exists($key, $changes)) {
                $idea->kind = is_scalar($changes[$key]) && (string) $changes[$key] !== '' ? (string) $changes[$key] : null;
            }
        }

        return $this->store->save($idea);
    }

    public function delete(int|string $id): void
    {
        $this->store->delete($id);
    }

    /**
     * Empty the list of every idea in one state, open or dismissed.
     *
     * @return int How many went.
     *
     * @throws Conflict for any other state
     */
    public function clear(string $status): int
    {
        if (! in_array($status, [Idea::OPEN, Idea::DISMISSED], true)) {
            throw new Conflict('Only open or dismissed ideas can be cleared.');
        }

        $gone = 0;

        foreach ($this->store->ideas() as $idea) {
            if ($idea->status === $status && $idea->id !== null) {
                $this->store->delete($idea->id);
                $gone++;
            }
        }

        return $gone;
    }

    /**
     * An idea whose piece has gone is just an idea again. Whether it changed.
     *
     * @param  callable(int|string): bool  $sessionExists
     */
    public static function release(Idea $idea, callable $sessionExists): bool
    {
        if (! $idea->isDrafted() || ($idea->session !== null && $sessionExists($idea->session))) {
            return false;
        }

        $idea->status = Idea::OPEN;
        $idea->session = null;

        return true;
    }

    /**
     * Whether a title is already on the plan, in any state.
     *
     * @param  iterable<Idea>  $ideas
     */
    public static function isDuplicate(string $title, iterable $ideas): bool
    {
        $key = Idea::titleKey($title);

        foreach ($ideas as $idea) {
            if ($idea->key() === $key) {
                return true;
            }
        }

        return false;
    }

    public function state(): PlanState
    {
        return $this->store->state();
    }

    /**
     * @param  callable(PlanState): void  $change
     *
     * @throws LockTimeout
     */
    public function changeState(callable $change): PlanState
    {
        return $this->lock->run(self::LOCK, function () use ($change) {
            $state = $this->store->state();
            $change($state);
            $this->store->saveState($state);

            return $state;
        });
    }

    private function find(int|string $id): Idea
    {
        return $this->store->find($id) ?? throw new NotFound('No such idea.');
    }
}
