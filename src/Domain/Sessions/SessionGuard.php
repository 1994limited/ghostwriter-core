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
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;

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
     * A new piece that starts in the conversation (since 1.6): Ghostwriter
     * asks for the quick details, and waits. Nothing runs yet. Make the
     * session with Session::start($format, $kind, [], $viewer->id, $examples),
     * the examples chosen as the brief screen chose them.
     */
    public function open(Session $session, Viewer $viewer, string $ask = BriefThread::ASK_TEXT): Session
    {
        $now = ($this->clock)();

        $session->addMessage('assistant', $ask, null, [BriefThread::KEY => ['step' => BriefThread::ASK]], $now);
        $session->touch($viewer->id);

        return $this->store->save($session);
    }

    /**
     * A new piece from a plan idea ("Draft this"): no question first;
     * Ghostwriter starts filling in the brief from the idea at once. Start
     * the brief's job after (BriefThread::fills() is true).
     */
    public function openFromIdea(Session $session, Viewer $viewer, string $title, string $notes = ''): Session
    {
        $now = ($this->clock)();
        $content = trim($title.(trim($notes) !== '' ? "\n\n".trim($notes) : ''));

        $session->addMessage('user', $content, $viewer->id, [BriefThread::KEY => ['step' => BriefThread::IDEA, 'title' => trim($title), 'notes' => trim($notes)]], $now);
        $session->claim($viewer->id, $this->options, $now);

        return $this->store->save($session);
    }

    /**
     * The person's reply to the quick-details question, and Ghostwriter set
     * to fill in the brief from it. Start the brief's job after.
     *
     * @throws Conflict when the piece isn't waiting for its details
     * @throws Busy while Ghostwriter works on it
     */
    public function details(string $id, string $reply, Viewer $viewer, string $busy = 'Ghostwriter is still working on the last message.'): Session
    {
        return $this->locked($id, $viewer, function (Session $session, DateTimeImmutable $now) use ($reply, $viewer, $busy) {
            if ($session->isWorking()) {
                throw $this->busy($session, $viewer, $busy);
            }

            if (BriefThread::stage($session) !== BriefStage::Details) {
                throw new Conflict('This piece already has its details.');
            }

            $session->claim($viewer->id, $this->options, $now);
            $session->addMessage('user', trim($reply), $viewer->id, [BriefThread::KEY => ['step' => BriefThread::DETAILS]], $now);
        });
    }

    /**
     * The brief Studio::fillBrief() filled in, as a card in the
     * conversation for the person to check; the stored brief (answers and
     * examples) is the card's. In the brief's job; null when the session
     * has gone. Call off (nothing saved) if the piece has moved on.
     *
     * @param  string  $open  Added to the message when the brief has something in square brackets.
     */
    public function propose(string $id, Brief $brief, int $inputTokens = 0, int $outputTokens = 0, string $text = BriefThread::CARD_TEXT, string $open = BriefThread::OPEN_TEXT): ?Session
    {
        $now = ($this->clock)();

        return $this->change($id, function (Session $session) use ($brief, $inputTokens, $outputTokens, $text, $open, $now) {
            if (! BriefThread::fills($session)) {
                return false;
            }

            $session->addMessage('assistant', $brief->open() !== [] ? $text.' '.$open : $text, null, [BriefThread::KEY => ['step' => BriefThread::CARD] + $brief->toArray() + ['agreed' => false]], $now);
            $session->answers = $brief->answers;
            $session->examples = $brief->examples;
            $session->usage = [
                'input' => (int) ($session->usage['input'] ?? 0) + $inputTokens,
                'output' => (int) ($session->usage['output'] ?? 0) + $outputTokens,
            ] + $session->usage;
            $session->status = Session::IDLE;
            $session->error = null;

            return null;
        });
    }

    /**
     * "Try again": another brief, from the same details, with the card as
     * the person left it (answers they changed are kept). Start the
     * brief's job after.
     *
     * @param  array<string, mixed>  $answers  The card's answers, by handle.
     * @param  array<int, int|string>|null  $examples  The card's ticked examples.
     *
     * @throws Conflict when there is no brief waiting to be checked
     * @throws Busy while Ghostwriter works on it
     */
    public function tryAgain(string $id, Viewer $viewer, array $answers = [], ?array $examples = null, ?string $title = null, string $message = BriefThread::TRY_AGAIN_TEXT): Session
    {
        return $this->locked($id, $viewer, function (Session $session, DateTimeImmutable $now) use ($viewer, $answers, $examples, $title, $message) {
            $this->proposed($session, $viewer);
            $session->claim($viewer->id, $this->options, $now);
            $session->addMessage('user', $message, $viewer->id, [BriefThread::KEY => array_filter([
                'step' => BriefThread::TRY_AGAIN,
                'answers' => self::answers($answers),
                'examples' => $examples !== null ? array_values($examples) : null,
                'title' => $title,
            ], fn ($value) => $value !== null)], $now);
        });
    }

    /**
     * "Looks right, start writing": the card, with the person's changes, is
     * the brief. It is stored on the piece (answers and examples), the
     * card is marked agreed, and its text is the message the writer starts
     * from. Start the turn's job after, as for start().
     *
     * Check required answers first, as the brief screen did
     * (ContentType::missing()); something left in square brackets counts
     * as an answer, for the writer to ask about.
     *
     * @param  callable(Brief): string  $text  The brief as text: fn (Brief $b) => $studio->brief($kind, $b->answers, $b->title).
     * @param  array<string, mixed>  $answers  The card's answers, by handle.
     * @param  array<int, int|string>|null  $examples  The card's ticked examples.
     *
     * @throws Conflict when there is no brief waiting to be checked
     * @throws Busy while Ghostwriter works on it
     */
    public function agree(string $id, Viewer $viewer, callable $text, array $answers = [], ?array $examples = null, ?string $title = null): Session
    {
        return $this->locked($id, $viewer, function (Session $session, DateTimeImmutable $now) use ($viewer, $text, $answers, $examples, $title) {
            $this->proposed($session, $viewer);
            $brief = $this->keepCard($session, $answers, $examples, $title, agreed: true);

            $session->claim($viewer->id, $this->options, $now);
            $session->addMessage('user', $text($brief), $viewer->id, [BriefThread::KEY => ['step' => BriefThread::AGREED]], $now);
        });
    }

    /**
     * A change to the agreed brief ("Show the brief", then edit): the
     * stored brief, the card and the text the writer works from. No turn
     * runs; the next one works from the new brief. Refused while
     * Ghostwriter works, as edit() is.
     *
     * @param  callable(Brief): string  $text  As for agree().
     * @param  array<string, mixed>  $answers
     * @param  array<int, int|string>|null  $examples
     *
     * @throws Conflict when the brief hasn't been agreed
     * @throws Busy while Ghostwriter works on the piece
     */
    public function editBrief(string $id, Viewer $viewer, callable $text, array $answers = [], ?array $examples = null, ?string $title = null): Session
    {
        return $this->edit($id, $viewer, function (Session $session) use ($text, $answers, $examples, $title) {
            if (! BriefThread::agreed($session)) {
                throw new Conflict('There is no agreed brief to change.');
            }

            $brief = $this->keepCard($session, $answers, $examples, $title, agreed: true);

            foreach ($session->messages as $index => $message) {
                if (BriefThread::step($message) === BriefThread::AGREED) {
                    $session->messages[$index]['content'] = $text($brief);
                }
            }
        });
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

    /**
     * @throws Busy|Conflict unless the card waits to be checked
     */
    private function proposed(Session $session, Viewer $viewer): void
    {
        if ($session->isWorking()) {
            throw $this->busy($session, $viewer, 'Ghostwriter is still working on the brief.');
        }

        if (BriefThread::stage($session) !== BriefStage::Proposed) {
            throw new Conflict('There is no brief waiting to be checked.');
        }
    }

    /**
     * The latest card with the person's changes, saved on it and on the
     * piece.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<int, int|string>|null  $examples
     */
    private function keepCard(Session $session, array $answers, ?array $examples, ?string $title, bool $agreed): Brief
    {
        $brief = (BriefThread::card($session) ?? new Brief('', []))->with(self::answers($answers), $examples, $title);

        foreach (array_reverse(array_keys($session->messages)) as $index) {
            if (BriefThread::step($session->messages[$index]) === BriefThread::CARD) {
                $session->messages[$index][BriefThread::KEY] = ['step' => BriefThread::CARD] + $brief->toArray() + ['agreed' => $agreed];

                break;
            }
        }

        $session->answers = $brief->answers;
        $session->examples = $brief->examples;

        return $brief;
    }

    /**
     * @param  array<mixed>  $answers
     * @return array<string, string>
     */
    private static function answers(array $answers): array
    {
        $out = [];

        foreach ($answers as $handle => $answer) {
            $out[(string) $handle] = trim(is_scalar($answer) ? (string) $answer : '');
        }

        return $out;
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
