<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;

/**
 * A Lock for one process: tests, and addon unit tests. It records which
 * keys were taken (`taken`), and can be told a key is held elsewhere, so
 * the next attempt times out (`holdElsewhere()`).
 */
final class InMemoryLock implements Lock
{
    /** @var array<int, string> Keys taken, in order. */
    public array $taken = [];

    /** @var array<string, int> */
    private array $held = [];

    /** @var array<string, true> */
    private array $elsewhere = [];

    public function run(string $key, callable $work, int $waitSeconds = 15): mixed
    {
        if (isset($this->elsewhere[$key])) {
            throw new LockTimeout;
        }

        $this->taken[] = $key;
        $this->held[$key] = ($this->held[$key] ?? 0) + 1;

        try {
            return $work();
        } finally {
            if (--$this->held[$key] === 0) {
                unset($this->held[$key]);
            }
        }
    }

    public function isHeld(string $key): bool
    {
        return isset($this->held[$key]);
    }

    public function holdElsewhere(string $key, bool $held = true): void
    {
        if ($held) {
            $this->elsewhere[$key] = true;
        } else {
            unset($this->elsewhere[$key]);
        }
    }
}
