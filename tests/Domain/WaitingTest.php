<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryWaitingStore;
use PHPUnit\Framework\TestCase;

final class WaitingTest extends TestCase
{
    public function test_work_not_picked_up_after_thirty_seconds_is_mentioned(): void
    {
        $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $store = new InMemoryWaitingStore;
        $waiting = new Waiting($store, DomainOptions::statamic(), clock: function () use (&$now) {
            return $now;
        });

        $waiting->queued('session:1');
        $this->assertSame(0, $waiting->waited('session:1'));
        $this->assertNull($waiting->notice('session:1', 'php artisan queue:work'));

        $now = $now->modify('+30 seconds');
        $this->assertSame('Still waiting for a queue worker to pick this up. Is “php artisan queue:work” running?', $waiting->notice('session:1', 'php artisan queue:work'));

        $waiting->started('session:1');
        $this->assertNull($waiting->waited('session:1'));
        $this->assertFalse($waiting->isWaiting('session:1'));
    }

    public function test_filament_words_it_its_own_way_and_a_queue_that_runs_itself_never_waits(): void
    {
        $store = new InMemoryWaitingStore;
        $store->mark('image:x', 1);

        $this->assertSame('Still waiting for a queue worker to pick this up. Is `php artisan queue:work --queue=default` running?', (new Waiting($store, DomainOptions::filament()))->notice('image:x', 'php artisan queue:work --queue=default'));
        $this->assertNull((new Waiting($store, DomainOptions::craft(), runsItself: true))->notice('image:x', 'x'));
    }
}
