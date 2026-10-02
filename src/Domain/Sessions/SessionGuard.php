<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Everything that changes a session, with its rules: who may see it
 * (SessionAccess), one run at a time, and the per-session lock around
 * every read-change-write, so two requests, or a request and the job
 * answering a turn, can't save over each other (E7, F2, F3).
 *
 * The addon's controllers and jobs call this instead of the store:
 *
 *     $session = $guard->send($id, $request->message, $viewer);   // then start the turn's job
 *     $guard->edit($id, $viewer, fn (Session $s) => $s->draft = $yaml);
 *     $guard->change($id, fn (Session $s) => $s->answer($reply, $draft, $in, $out));   // in the job
 *
 * Refusals are exceptions with the status to answer with: NotFound (404),
 * NotAllowed (403), Busy and Conflict (409), LockTimeout (409).
 */
final class SessionGuard
{
    private readonly SessionAccess $access;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly SessionStore $store,
        private readonly Lock $lock,
        private readonly DomainOptions $options,
        ?Closure $clock = null,
    ) {
        $this->access = new SessionAccess($options);
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function access(): SessionAccess
    {
        return $this->access;
    }

    /**
     * A session the person may see, with a run that stopped without
     * finishing shown as failed (CRA-2).
     *
     * @throws NotFound when there is none
     * @throws NotAllowed when it is someone else's private conversation
     */
    public function find(string $id, Viewer $viewer): Session
    {
        $session = $this->store->find($id) ?? throw new NotFound;

        if (! $this->access->canSee($session, $viewer)) {
            throw new NotAllowed('This piece is someone else’s.');
        }

        $session->recoverIfStale($this->options, ($this->clock)());

        return $session;
    }

    /**
     * The sessions the person may see, the most recently changed first.
     *
     * @return array<int, Session>
     */
    public function visible(Viewer $viewer): array
    {
        $sessions = $this->options->shared || ! $viewer->isSomeone() || $this->options->unownedIsAnyones || $this->options->adminSeesAll
            ? $this->store->all()
            : $this->store->startedBy((string) $viewer->id);

        $now = ($this->clock)();

        return array_map(function (Session $session) use ($now) {
            $session->recoverIfStale($this->options, $now);

            return $session;
        }, $this->access->visible($sessions, $viewer));
    }

    /**
     * A new piece from a brief: the brief is its first message, and
     * Ghostwriter starts on it at once. Start the turn's job after.
     */
    public function start(Session $session, string $brief, Viewer $viewer): Session
    {
        $now = ($this->clock)();

        $session->addMessage('user', $brief, $viewer->id, now: $now);
        $session->claim($viewer->id, $this->options, $now);

        return $this->store->save($session);
    }

    /**
     * A message from the person, and Ghostwriter set to answer it. Start
     * the turn's job after.
     *
     * @param  array<string, mixed>  $extra  More about the message.
     *
     * @throws Busy while Ghostwriter answers a request (anyone's)
     */
    public function send(string $id, string $message, Viewer $viewer, string $busy = 'Ghostwriter is still working on the last message.', array $extra = []): Session
    {
        return $this->locked($id, $viewer, function (Session $session, DateTimeImmutable $now) use ($message, $viewer, $busy, $extra) {
            if (! $session->claim($viewer->id, $this->options, $now)) {
                throw $this->busy($session, $viewer, $busy);
            }

            $session->addMessage('user', $message, $viewer->id, $extra, $now);
        });
    }

    /**
     * Run the last turn again after it failed, for whoever asks now.
     *
     * @throws Conflict when there is nothing to try again
     * @throws Busy when someone else got there first
     */
    public function retry(string $id, Viewer $viewer): Session
    {
        return $this->locked($id, $viewer, function (Session $session, DateTimeImmutable $now) use ($viewer) {
            if ($session->isWorking()) {
                throw $this->busy($session, $viewer, 'Ghostwriter is still working on the last message.');
            }

            if (! $session->canRetry() || ! $session->claim($viewer->id, $this->options, $now, Session::FAILED)) {
                throw new Conflict('There is nothing to try again.');
            }
        });
    }

    /**
     * A change the person makes by hand while nothing runs: editing the
     * draft, or one field of it (F3). It is refused while Ghostwriter
     * works, as the turn would save over it. The change may return false
     * to call it off (nothing is saved). An image choice may be made while
     * a turn runs: use change(), and SessionImages::mergeTurn() in the job.
     *
     * @param  callable(Session): (void|bool)  $change
     *
     * @throws Busy while Ghostwriter works on the piece
     */
    public function edit(string $id, Viewer $viewer, callable $change, string $busy = 'Ghostwriter is still working on the draft. Try again when it has finished.'): Session
    {
        return $this->locked($id, $viewer, function (Session $session) use ($viewer, $change, $busy) {
            if ($session->isWorking()) {
                throw $this->busy($session, $viewer, $busy);
            }

            if ($change($session) === false) {
                return false;
            }

            $session->touch($viewer->id);

            return null;
        });
    }

    /**
     * A change to the session as it stands now, under its lock and whatever
     * it is doing: the job saving a turn's answer, or a choice made after
     * slow work (fetching a photograph) landing on whatever was saved
     * meanwhile. The change may return false to call it off. Null when the
     * session has gone.
     *
     * @param  callable(Session): (void|bool)  $change
     */
    public function change(string $id, callable $change): ?Session
    {
        return $this->lock->run($this->key($id), function () use ($id, $change) {
            $session = $this->store->find($id);

            if ($session === null) {
                return null;
            }

            if ($change($session) === false) {
                return $session;
            }

            return $this->store->save($session);
        });
    }

    /**
     * The person put the draft into a form (or the record's draft).
     */
    public function applied(string $id, Viewer $viewer): ?Session
    {
        $now = ($this->clock)();

        return $this->change($id, fn (Session $session) => $session->markApplied($viewer->id, $now));
    }

    /**
     * Delete a piece and its conversation: its starter or a manager, when
     * shared (Q1).
     *
     * @throws NotAllowed
     */
    public function delete(string $id, Viewer $viewer, string $message = 'Only the person who started this piece, or someone who manages Ghostwriter, can delete it.'): void
    {
        $session = $this->find($id, $viewer);

        if (! $this->access->canDelete($session, $viewer)) {
            throw new NotAllowed($message);
        }

        $this->lock->run($this->key($id), fn () => $this->store->delete($id));
    }

    /**
     * Seconds after which work marked as running has stopped.
     */
    public function staleAfter(): int
    {
        return $this->options->staleAfter();
    }

    /**
     * @param  callable(Session, DateTimeImmutable): (void|bool|null)  $change
     */
    private function locked(string $id, Viewer $viewer, callable $change): Session
    {
        return $this->lock->run($this->key($id), function () use ($id, $viewer, $change) {
            $session = $this->find($id, $viewer);

            if ($change($session, ($this->clock)()) === false) {
                return $session;
            }

            return $this->store->save($session);
        });
    }

    private function busy(Session $session, Viewer $viewer, string $message): Busy
    {
        return new Busy($message, $session->waitingOn($viewer), $this->options->waitingOnOther);
    }

    private function key(string $id): string
    {
        return 'session:'.$id;
    }
}
