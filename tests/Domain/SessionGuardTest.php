<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemorySessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use PHPUnit\Framework\TestCase;

final class SessionGuardTest extends TestCase
{
    private InMemorySessionStore $store;

    private InMemoryLock $lock;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $this->store = new InMemorySessionStore(Format::Craft, fn () => $this->now);
        $this->lock = new InMemoryLock;
    }

    private function guard(?DomainOptions $options = null): SessionGuard
    {
        return new SessionGuard($this->store, $this->lock, $options ?? DomainOptions::craft(), fn () => $this->now);
    }

    private function started(int $by = 1): Session
    {
        return $this->guard()->start(Session::start(Format::Craft, 'guide', ['subject' => 'Bulbs'], $by), 'The brief.', new Viewer($by));
    }

    public function test_a_new_piece_starts_with_its_brief_and_a_run(): void
    {
        $session = $this->started();

        $found = $this->store->find($session->id);
        $this->assertNotNull($found);
        $this->assertSame(Session::WORKING, $found->status);
        $this->assertSame(1, $found->runBy);
        $this->assertSame(['role' => 'user', 'content' => 'The brief.', 'at' => '2026-10-02T12:00:00+00:00', 'by' => 1], $found->messages[0]);
    }

    public function test_one_request_at_a_time_and_it_says_whose(): void
    {
        $session = $this->started(1);

        try {
            $this->guard()->send($session->id, 'Shorter please.', new Viewer(2));
            $this->fail('Busy');
        } catch (Busy $busy) {
            $this->assertSame(409, $busy->status());
            $this->assertSame(1, $busy->waitingOn);
            $this->assertSame('Daniel is waiting on Ghostwriter.', $busy->messageFor(fn ($id) => $id === 1 ? 'Daniel' : 'Someone'));
        }

        try {
            $this->guard()->send($session->id, 'Shorter please.', new Viewer(1));
            $this->fail('Busy');
        } catch (Busy $busy) {
            $this->assertNull($busy->waitingOn);
            $this->assertSame('Ghostwriter is still working on the last message.', $busy->messageFor(fn () => 'x'));
        }

        $this->assertCount(1, $this->store->find($session->id)->messages ?? [], 'Nothing was added.');
        $this->assertSame(['session:'.$session->id, 'session:'.$session->id], $this->lock->taken, 'Each under the lock.');
    }

    public function test_statamic_and_filament_name_the_wait_their_way(): void
    {
        $busy = new Busy('x', 1, DomainOptions::statamic()->waitingOnOther);
        $this->assertSame('Pat is waiting on Ghostwriter. Try again when it has answered.', $busy->messageFor(fn () => 'Pat'));
    }

    public function test_a_message_is_sent_once_the_run_has_answered(): void
    {
        $session = $this->started(1);
        $this->guard()->change($session->id, fn (Session $s) => $s->answer('Here.', "title: Bulbs\n", 1, 1));

        $sent = $this->guard()->send($session->id, 'Shorter please.', new Viewer(2));

        $this->assertSame(Session::WORKING, $sent->status);
        $this->assertSame(2, $sent->runBy);
        $this->assertSame(2, $sent->lastMessage()['by'] ?? null);
        $this->assertSame('Shorter please.', $this->store->find($session->id)?->lastMessage()['content'] ?? null);
    }

    public function test_a_run_that_stopped_is_shown_failed_and_can_be_sent_to_again(): void
    {
        $session = $this->started(1);
        $this->now = $this->now->modify('+1 hour');

        $found = $this->guard()->find($session->id, new Viewer(2));
        $this->assertSame(Session::FAILED, $found->status);
        $this->assertSame(DomainOptions::STOPPED, $found->error);

        $this->assertSame(Session::WORKING, $this->guard()->send($session->id, 'Again', new Viewer(2))->status);
    }

    public function test_retry_runs_the_failed_turn_again_for_whoever_asks(): void
    {
        $session = $this->started(1);

        try {
            $this->guard()->retry($session->id, new Viewer(2));
            $this->fail('Busy');
        } catch (Busy $busy) {
            $this->assertSame(1, $busy->waitingOn);
        }

        $this->guard()->change($session->id, fn (Session $s) => $s->fail('Overloaded.'));
        $retried = $this->guard()->retry($session->id, new Viewer(2));
        $this->assertSame(Session::WORKING, $retried->status);
        $this->assertSame(2, $retried->runBy);

        $this->guard()->change($session->id, fn (Session $s) => $s->answer('Done.', null));
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('There is nothing to try again.');
        $this->guard()->retry($session->id, new Viewer(2));
    }

    public function test_hand_edits_and_image_choices_wait_for_the_run(): void
    {
        $session = $this->started(1);

        try {
            $this->guard()->edit($session->id, new Viewer(2), fn (Session $s) => $s->draft = 'title: Mine');
            $this->fail('Busy');
        } catch (Busy $busy) {
            $this->assertSame('Ghostwriter is still working on the draft. Try again when it has finished.', $busy->getMessage());
        }

        $this->assertNull($this->store->find($session->id)?->draft, 'Not saved over by the turn later.');

        $this->guard()->change($session->id, fn (Session $s) => $s->answer('Here.', "title: Bulbs\n"));
        $edited = $this->guard()->edit($session->id, new Viewer(2), fn (Session $s) => SessionImages::choose($s, 'hero', 'a.jpg', '/a.jpg'));

        $this->assertSame('done', $edited->images['hero']['status']);
        $this->assertSame(2, $edited->touchedBy);
    }

    public function test_an_edit_can_be_called_off(): void
    {
        $session = $this->started(1);
        $this->guard()->change($session->id, fn (Session $s) => $s->answer('Here.', "title: Bulbs\n"));
        $before = $this->store->records[$session->id];

        $this->guard()->edit($session->id, new Viewer(2), function (Session $s) {
            $s->draft = 'title: Not this';

            return false;
        });

        $this->assertSame($before, $this->store->records[$session->id]);
    }

    public function test_a_change_after_slow_work_lands_on_the_session_as_it_is_now(): void
    {
        $session = $this->started(1);

        $this->assertNull($this->guard()->change('0123456789abcdef0123456789', fn () => null), 'Gone: nothing.');

        $changed = $this->guard()->change($session->id, fn (Session $s) => $s->answer('Here.', "title: Bulbs\n", 5, 5));
        $this->assertSame(Session::IDLE, $changed?->status);
    }

    public function test_a_piece_can_be_put_into_the_form(): void
    {
        $session = $this->started(1);
        $applied = $this->guard()->applied($session->id, new Viewer(3));

        $this->assertSame('2026-10-02T12:00:00+00:00', $applied?->appliedAt);
        $this->assertSame(3, $applied?->touchedBy);
    }

    public function test_only_the_starter_or_a_manager_deletes_a_shared_piece(): void
    {
        $session = $this->started(1);

        try {
            $this->guard()->delete($session->id, new Viewer(2));
            $this->fail('Not allowed');
        } catch (NotAllowed $refused) {
            $this->assertSame(403, $refused->status());
        }

        $this->guard()->delete($session->id, new Viewer(9, manager: true));
        $this->assertNull($this->store->find($session->id));
    }

    public function test_a_private_piece_is_not_found_by_anyone_else(): void
    {
        $session = $this->started(1);

        $this->expectException(NotAllowed::class);
        $this->guard(DomainOptions::craft(shared: false))->find($session->id, new Viewer(2));
    }

    public function test_a_missing_piece_is_not_found(): void
    {
        $this->expectException(NotFound::class);
        $this->guard()->find('nothing', new Viewer(1));
    }

    public function test_the_list_is_what_the_person_may_see(): void
    {
        $mine = $this->started(1);
        $theirs = $this->started(2);

        $this->assertSame([$theirs->id, $mine->id], array_map(fn (Session $s) => $s->id, $this->guard()->visible(new Viewer(1))));
        $this->assertSame([$mine->id], array_map(fn (Session $s) => $s->id, $this->guard(DomainOptions::craft(shared: false))->visible(new Viewer(1))));
        $this->assertSame([], $this->guard()->visible(Viewer::nobody()));
    }

    public function test_a_lock_held_elsewhere_is_reported(): void
    {
        $session = $this->started(1);
        $this->lock->holdElsewhere('session:'.$session->id);

        $this->expectException(LockTimeout::class);
        $this->guard()->send($session->id, 'Hello', new Viewer(1));
    }
}
