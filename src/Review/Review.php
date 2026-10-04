<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Ulid;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * The comments on a piece (Session::$review): its threads, the next pin
 * number, and a version that goes up with every change, so a panel that
 * polls knows when to fetch them again, and a change made from an old
 * copy can be refused (optimistic: SessionReview's `$version`).
 *
 * Only the states and the limits are here; who may do what is
 * ReviewRules, and the locking SessionReview.
 */
final class Review
{
    /** The most threads a piece keeps. */
    public const MAX_THREADS = 100;

    /** The most notes in one thread. */
    public const MAX_NOTES = 30;

    /** The most threads one Apply sends; the rest wait for the next. */
    public const PER_APPLY = 12;

    /**
     * @param  list<Thread>  $threads  In the order they were made.
     */
    public function __construct(
        public array $threads = [],
        public int $next = 1,
        public int $version = 0,
    ) {}

    /** @return list<Thread> */
    public function all(): array
    {
        return $this->threads;
    }

    public function get(string $threadId): ?Thread
    {
        foreach ($this->threads as $thread) {
            if ($thread->id === $threadId) {
                return $thread;
            }
        }

        return null;
    }

    /**
     * @throws NotFound
     */
    public function find(string $threadId): Thread
    {
        return $this->get($threadId) ?? throw new NotFound('There is no such comment on this piece.');
    }

    /**
     * Threads not sent yet, by number: what Apply sends (up to PER_APPLY).
     *
     * @return list<Thread>
     */
    public function open(): array
    {
        return $this->having(ThreadStatus::Open);
    }

    /**
     * Threads in the run going now.
     *
     * @return list<Thread>
     */
    public function sending(): array
    {
        return $this->having(ThreadStatus::Sending);
    }

    /**
     * How many threads are in each state, every state listed.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(array_map(fn (ThreadStatus $status) => $status->value, ThreadStatus::cases()), 0);

        foreach ($this->threads as $thread) {
            $counts[$thread->status->value]++;
        }

        return $counts;
    }

    /**
     * A new comment: thread number `next`, Not sent.
     *
     * @throws Conflict at MAX_THREADS, or for an empty comment
     */
    public function add(Scope $scope, string $body, Viewer $by, ?DateTimeInterface $now = null): Thread
    {
        if (count($this->threads) >= self::MAX_THREADS) {
            throw new Conflict('This piece has '.self::MAX_THREADS.' comments already. Resolve or delete some first.');
        }

        $thread = new Thread(Ulid::generate(), $this->next++, $scope, ThreadStatus::Open, [self::note(NoteKind::Comment, $by->id, $body, $now)], $by->id);
        $this->threads[] = $thread;
        $this->version++;

        return $thread;
    }

    /**
     * The comment's words changed, before it is sent.
     *
     * @throws Conflict once it has been sent
     */
    public function edit(string $threadId, string $body): Thread
    {
        $thread = $this->find($threadId);

        if ($thread->status !== ThreadStatus::Open || $thread->lastAnswer() !== null) {
            throw new Conflict('This comment has been sent. Reply to it instead.');
        }

        $thread->notes[0] = $thread->notes[0]->withBody(self::body($body));
        $this->version++;

        return $thread;
    }

    /**
     * A reply from the editor: the thread goes with the next Apply (Not
     * sent), unless it is Detached.
     *
     * @throws Conflict while it is being revised, or at MAX_NOTES
     */
    public function reply(string $threadId, string $body, Viewer $by, ?DateTimeInterface $now = null): Thread
    {
        $thread = $this->find($threadId);

        if ($thread->status === ThreadStatus::Sending) {
            throw new Conflict('Ghostwriter is revising this now. Reply when it has answered.');
        }

        $this->addNote($thread, self::note(NoteKind::Comment, $by->id, $body, $now));

        if ($thread->status !== ThreadStatus::Detached) {
            $thread->status = ThreadStatus::Open;
            $thread->resolvedAt = null;
            $thread->resolvedBy = null;
        }

        $this->version++;

        return $thread;
    }

    /**
     * @throws Conflict while it is being revised
     */
    public function resolve(string $threadId, Viewer $by, ?DateTimeInterface $now = null): Thread
    {
        $thread = $this->find($threadId);

        if ($thread->status === ThreadStatus::Sending) {
            throw new Conflict('Ghostwriter is revising this now. Resolve it when it has answered.');
        }

        if ($thread->status !== ThreadStatus::Resolved) {
            $thread->status = ThreadStatus::Resolved;
            $thread->resolvedAt = ($now ?? new DateTimeImmutable)->format(DATE_ATOM);
            $thread->resolvedBy = $by->id;
            $this->version++;
        }

        return $thread;
    }

    /**
     * Resolved → what it was: Changed or Replied once Ghostwriter has
     * answered, Not sent before, and Detached when its units are gone.
     *
     * @param  list<string>|null  $units  The draft's unit ids now (Session::$units' keys); null to skip the check.
     */
    public function reopen(string $threadId, ?array $units = null): Thread
    {
        $thread = $this->find($threadId);

        if ($thread->status !== ThreadStatus::Resolved) {
            return $thread;
        }

        $thread->status = $units !== null && self::gone($thread->scope, $units) ? ThreadStatus::Detached : $thread->answeredStatus();
        $thread->resolvedAt = null;
        $thread->resolvedBy = null;
        $this->version++;

        return $thread;
    }

    /**
     * A thread anchored again ("Pin to a block" on a Detached one, or a
     * comment moved): it goes with the next Apply.
     *
     * @throws Conflict while it is being revised
     */
    public function repin(string $threadId, Scope $scope): Thread
    {
        $thread = $this->find($threadId);

        if ($thread->status === ThreadStatus::Sending) {
            throw new Conflict('Ghostwriter is revising this now.');
        }

        $thread->scope = $scope;
        $thread->status = ThreadStatus::Open;
        $thread->resolvedAt = null;
        $thread->resolvedBy = null;
        $this->version++;

        return $thread;
    }

    /**
     * @throws Conflict while it is being revised
     */
    public function remove(string $threadId): void
    {
        $thread = $this->find($threadId);

        if ($thread->status === ThreadStatus::Sending) {
            throw new Conflict('Ghostwriter is revising this now. Delete it when it has answered.');
        }

        $this->threads = array_values(array_filter($this->threads, fn (Thread $each) => $each->id !== $threadId));
        $this->version++;
    }

    /**
     * After the draft's units changed (a chat turn, an edit, a revision):
     * each thread keeps the units that are still there. One whose units are
     * all gone, or whose quoted words are gone from its unit, is Detached;
     * a Detached one whose units are back returns to what it was. Resolved
     * and Sending threads keep their state. A quote found only fuzzily is
     * taken again from the text. Whether anything changed.
     *
     * @param  list<string>|null  $extras  The extra item ids there are now; null leaves extra items in scopes alone.
     */
    public function reanchor(Units $units, ?array $extras = null): bool
    {
        $changed = false;
        $finder = new QuoteFinder;

        foreach ($this->threads as $thread) {
            $scope = $thread->scope;

            if ($scope->kind === ScopeKind::Page || $thread->status === ThreadStatus::Sending) {
                continue;
            }

            $kept = array_values(array_filter($scope->units, fn (string $id) => Scope::isUnit($id) ? $units->get($id) !== null : ($extras === null || in_array($id, $extras, true))));
            $quote = $scope->quote;
            $lost = $kept === [];

            if (! $lost && $scope->kind === ScopeKind::Text && $quote !== null) {
                $unit = $units->get($kept[0]);
                $text = $unit === null ? '' : $unit->markdown;
                $match = $finder->find($quote, $text, markdown: true);
                $lost = $match === null;
                $quote = $match !== null && $match->fuzzy ? $match->requote($text) : $quote;
            }

            if ($kept !== [] && ($kept !== $scope->units || $quote !== $scope->quote)) {
                $thread->scope = $scope->withUnits($kept, $quote);
                $changed = true;
            }

            if ($thread->status === ThreadStatus::Resolved) {
                continue;
            }

            if ($lost && $thread->status !== ThreadStatus::Detached) {
                $thread->status = ThreadStatus::Detached;
                $changed = true;
            } elseif (! $lost && $thread->status === ThreadStatus::Detached) {
                $thread->status = $thread->answeredStatus();
                $changed = true;
            }
        }

        if ($changed) {
            $this->version++;
        }

        return $changed;
    }

    /**
     * Puts up to PER_APPLY Not sent threads into a run: Sending, with the
     * version and each unit's hash now. The threads sent, by number.
     *
     * @param  array<string, string>  $hashes  Unit (or extra item) id => its hash now.
     * @return list<Thread>
     */
    public function send(array $hashes): array
    {
        $sent = array_slice($this->open(), 0, self::PER_APPLY);

        if ($sent === []) {
            return [];
        }

        $this->version++;

        foreach ($sent as $thread) {
            $thread->status = ThreadStatus::Sending;
            $thread->sentAtVersion = $this->version;
            $ids = $thread->scope->kind === ScopeKind::Page ? array_keys($hashes) : $thread->scope->units;
            $thread->hashes = array_intersect_key($hashes, array_flip($ids));
        }

        return $sent;
    }

    /**
     * Ghostwriter's answer to a thread in the run: Changed with changes, else Replied.
     *
     * @param  list<Change>  $changes
     */
    public function answer(string $threadId, string $body, array $changes = [], ?DateTimeInterface $now = null): Thread
    {
        $thread = $this->find($threadId);
        $this->addNote($thread, new Note(Ulid::generate(), $changes === [] ? NoteKind::Reply : NoteKind::Change, null, $body, self::stamp($now), array_values($changes)), force: true);
        $thread->status = $changes === [] ? ThreadStatus::Replied : ThreadStatus::Changed;
        $thread->hashes = [];
        $this->version++;

        return $thread;
    }

    /**
     * A thread in the run sent back to Not sent (refused, conflicted, or the
     * run failed), with a line saying why.
     */
    public function sendBack(string $threadId, string $why, ?DateTimeInterface $now = null): Thread
    {
        $thread = $this->find($threadId);
        $this->addNote($thread, self::note(NoteKind::System, null, $why, $now), force: true);
        $thread->status = ThreadStatus::Open;
        $thread->hashes = [];
        $this->version++;

        return $thread;
    }

    /** A system line in a thread ("Put back by Daniel"), its state unchanged. */
    public function remark(string $threadId, string $line, int|string|null $by = null, ?DateTimeInterface $now = null): Thread
    {
        $thread = $this->find($threadId);
        $this->addNote($thread, self::note(NoteKind::System, $by, $line, $now), force: true);
        $this->version++;

        return $thread;
    }

    /**
     * @return array{threads: list<array<string, mixed>>, next: int, version: int}
     */
    public function toArray(): array
    {
        return ['threads' => array_map(fn (Thread $thread) => $thread->toArray(), $this->threads), 'next' => $this->next, 'version' => $this->version];
    }

    /**
     * @param  array<mixed>  $array  Session::$review.
     */
    public static function fromArray(array $array): self
    {
        $threads = [];

        foreach (is_array($array['threads'] ?? null) ? $array['threads'] : [] as $raw) {
            if (is_array($raw) && ($thread = Thread::fromArray($raw)) !== null) {
                $threads[] = $thread;
            }
        }

        $highest = max([0, ...array_map(fn (Thread $thread) => $thread->number, $threads)]);

        return new self($threads, max($highest + 1, is_int($array['next'] ?? null) ? $array['next'] : 1), is_int($array['version'] ?? null) ? $array['version'] : 0);
    }

    /**
     * Whether none of a scope's units is among these.
     *
     * @param  list<string>  $units
     */
    public static function gone(Scope $scope, array $units): bool
    {
        if ($scope->kind === ScopeKind::Page) {
            return false;
        }

        $mine = array_filter($scope->units, fn (string $id) => Scope::isUnit($id));

        return $mine !== [] && array_intersect($mine, $units) === [];
    }

    /**
     * @return list<Thread>
     */
    private function having(ThreadStatus $status): array
    {
        $threads = array_values(array_filter($this->threads, fn (Thread $thread) => $thread->status === $status));
        usort($threads, fn (Thread $a, Thread $b) => $a->number <=> $b->number);

        return $threads;
    }

    private function addNote(Thread $thread, Note $note, bool $force = false): void
    {
        if (! $force && count($thread->notes) >= self::MAX_NOTES) {
            throw new Conflict('This thread is as long as a thread can be. Start a new comment.');
        }

        $thread->notes[] = $note;
    }

    private static function note(NoteKind $kind, int|string|null $by, string $body, ?DateTimeInterface $now): Note
    {
        return new Note(Ulid::generate(), $kind, $by, $kind === NoteKind::System ? trim($body) : self::body($body), self::stamp($now));
    }

    /**
     * @throws Conflict for an empty one
     */
    private static function body(string $body): string
    {
        if (trim($body) === '') {
            throw new Conflict('A comment needs some words.');
        }

        return $body;
    }

    private static function stamp(?DateTimeInterface $now): string
    {
        return ($now ?? new DateTimeImmutable)->format(DATE_ATOM);
    }
}
