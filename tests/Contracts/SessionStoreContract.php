<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;

/**
 * What every SessionStore must do. An addon runs it against its own store
 * by extending SessionStoreContractTest (PHPUnit), or by using this trait
 * in its own test case (a Codeception unit test, with Craft booted):
 *
 *     final class SessionStoreTest extends SessionStoreContractTest
 *     {
 *         protected function sessionStore(): SessionStore { return new FileSessionStore(...); }
 *         protected function storeFormat(): Format { return Format::Statamic; }
 *     }
 *
 * Override contractUser() where user IDs must exist (Craft's foreign key).
 * One test waits a second, to see the order by last change.
 */
trait SessionStoreContract
{
    abstract protected function sessionStore(): SessionStore;

    abstract protected function storeFormat(): Format;

    /**
     * A user ID the store will take: the nth test user.
     */
    protected function contractUser(int $n): int|string
    {
        return $this->storeFormat() === Format::Statamic ? "user-{$n}" : $n;
    }

    private function newSession(string $kind = 'any:journal', int $user = 1): Session
    {
        $session = Session::start($this->storeFormat(), $kind, ['subject' => 'Bulbs in October — “why”, not just “when”'], $this->contractUser($user), group: 'journal');
        $session->addMessage('user', 'Here is the brief: ünïcode, emoji 🌷 and "quotes".', $this->contractUser($user));

        return $session;
    }

    public function test_a_saved_session_is_found_as_it_was(): void
    {
        $store = $this->sessionStore();
        $session = $this->newSession();
        $session->draft = "title: Bulbs\nbody: |\n  Plant them in October.";
        $session->images = ['hero_image' => ['status' => 'done', 'path' => 'journal/bulbs.jpg', 'url' => '/assets/journal/bulbs.jpg', 'error' => null]];
        $session->usage = ['input' => 120, 'output' => 45];

        $store->save($session);
        $found = $store->find($session->id);

        $this->assertNotNull($found);
        $this->assertSame($session->id, $found->id);
        $this->assertSame('any:journal', $found->kind);
        $this->assertSame($session->answers, $found->answers);
        $this->assertSame($session->messages, $found->messages);
        $this->assertSame($session->draft, $found->draft);
        $this->assertSame($session->images, $found->images);
        $this->assertSame(['input' => 120, 'output' => 45], $found->usage);
        $this->assertSame((string) $this->contractUser(1), (string) $found->startedBy);
        $this->assertSame(Session::IDLE, $found->status);
    }

    public function test_saving_stamps_when_it_changed(): void
    {
        $store = $this->sessionStore();
        $session = $this->newSession();
        $session->updatedAt = null;

        $saved = $store->save($session);

        $this->assertNotNull($saved->updatedAt);
        $this->assertNotNull(Format::parse($saved->updatedAt));
        $this->assertLessThan(120, abs((new DateTimeImmutable)->getTimestamp() - (int) Format::parse($saved->updatedAt)?->getTimestamp()));
    }

    public function test_a_change_is_saved_over_the_session(): void
    {
        $store = $this->sessionStore();
        $session = $store->save($this->newSession());

        $found = $store->find($session->id);
        $this->assertNotNull($found);
        $this->assertTrue($found->claim($this->contractUser(2), new DomainOptions($this->storeFormat())));
        $store->save($found);

        $again = $store->find($session->id);
        $this->assertNotNull($again);
        $this->assertSame(Session::WORKING, $again->status);
        $this->assertSame((string) $this->contractUser(2), (string) $again->runBy);
        $this->assertSame((string) $this->contractUser(2), (string) $again->touchedBy);
        $this->assertCount(1, $store->all());
    }

    public function test_every_session_is_listed_the_latest_change_first(): void
    {
        $store = $this->sessionStore();
        $first = $store->save($this->newSession('any:journal'));
        $second = $store->save($this->newSession('any:pages'));

        sleep(1);
        $first->draft = 'title: Changed';
        $store->save($first);

        $this->assertSame([$first->id, $second->id], array_map(fn (Session $session) => $session->id, $store->all()));
    }

    public function test_sessions_are_listed_by_who_started_them(): void
    {
        $store = $this->sessionStore();
        $mine = $store->save($this->newSession('any:journal', 1));
        $store->save($this->newSession('any:pages', 2));

        $this->assertSame([$mine->id], array_map(fn (Session $session) => $session->id, $store->startedBy($this->contractUser(1))));
        $this->assertSame([], $store->startedBy($this->contractUser(3)));
    }

    public function test_nothing_is_found_for_a_missing_or_malformed_id(): void
    {
        $store = $this->sessionStore();
        $store->save($this->newSession());

        $this->assertNull($store->find($this->storeFormat()->newId()));

        foreach (['', 'x', '../../etc/passwd', str_repeat('a', 300), "01M3WWM3KK\0W1FV098209K5HHYE"] as $id) {
            $this->assertNull($store->find($id), "Nothing for \"{$id}\"");
        }
    }

    public function test_a_deleted_session_is_gone(): void
    {
        $store = $this->sessionStore();
        $kept = $store->save($this->newSession('any:journal'));
        $gone = $store->save($this->newSession('any:pages'));

        $store->delete($gone->id);
        $store->delete($gone->id);
        $store->delete($this->storeFormat()->newId());

        $this->assertNull($store->find($gone->id));
        $this->assertSame([$kept->id], array_map(fn (Session $session) => $session->id, $store->all()));
    }

    public function test_a_stored_session_is_written_in_the_formats_shape(): void
    {
        $store = $this->sessionStore();
        $session = $store->save($this->newSession());
        $found = $store->find($session->id);

        $this->assertNotNull($found);
        $this->assertSame($this->storeFormat(), $found->format);
        $this->assertTrue($this->storeFormat()->isSessionId($found->id));

        if ($this->storeFormat() === Format::Filament) {
            $this->assertNotNull($found->key, 'A Filament session has its row key once saved.');
        }
    }
}
