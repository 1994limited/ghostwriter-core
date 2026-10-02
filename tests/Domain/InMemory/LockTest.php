<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LockContractTest;

final class LockTest extends LockContractTest
{
    private ?InMemoryLock $lock = null;

    protected function contractLock(): InMemoryLock
    {
        return $this->lock ??= new InMemoryLock;
    }

    public function test_a_key_held_elsewhere_times_out(): void
    {
        $lock = $this->contractLock();
        $lock->holdElsewhere('session:a');

        $this->expectException(LockTimeout::class);

        $lock->run('session:a', fn () => 'never');
    }

    public function test_it_records_what_was_taken_and_lets_go(): void
    {
        $lock = $this->contractLock();
        $lock->run('session:a', fn () => $this->assertTrue($lock->isHeld('session:a')));

        $this->assertSame(['session:a'], $lock->taken);
        $this->assertFalse($lock->isHeld('session:a'));
    }
}
