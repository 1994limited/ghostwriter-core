<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use RuntimeException;

/**
 * What every Lock must do, as far as one process can see. Whether it
 * holds across processes is for the addon to show with its own test (two
 * processes, or its framework's lock fake).
 */
trait LockContract
{
    abstract protected function contractLock(): Lock;

    public function test_the_work_runs_and_its_result_is_returned(): void
    {
        $ran = 0;

        $this->assertSame('done', $this->contractLock()->run('session:a', function () use (&$ran) {
            $ran++;

            return 'done';
        }));
        $this->assertSame(1, $ran);
    }

    public function test_the_lock_is_let_go_however_the_work_ends(): void
    {
        $lock = $this->contractLock();

        try {
            $lock->run('session:a', fn () => throw new RuntimeException('Failed.'), 1);
            $this->fail('The exception should reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failed.', $exception->getMessage());
        }

        $this->assertSame(2, $lock->run('session:a', fn () => 2, 1), 'Taken again after the work threw.');
    }

    public function test_different_keys_do_not_wait_on_each_other(): void
    {
        $lock = $this->contractLock();

        $this->assertSame('inner', $lock->run('session:a', fn () => $lock->run('session:b', fn () => 'inner', 1), 1));
    }

    public function test_the_same_key_taken_again_inside_never_deadlocks(): void
    {
        $lock = $this->contractLock();
        $started = time();

        try {
            $result = $lock->run('session:a', fn () => $lock->run('session:a', fn () => 'inner', 1), 1);
            $this->assertSame('inner', $result);
        } catch (LockTimeout) {
            $this->assertLessThan(10, time() - $started, 'Gave up within the wait.');
        }
    }
}
