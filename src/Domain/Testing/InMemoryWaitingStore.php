<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;

/**
 * A WaitingStore in memory.
 */
final class InMemoryWaitingStore implements WaitingStore
{
    /** @var array<string, int> */
    public array $marks = [];

    public function mark(string $subject, int $at): void
    {
        $this->marks[$subject] = $at;
    }

    public function unmark(string $subject): void
    {
        unset($this->marks[$subject]);
    }

    public function markedAt(string $subject): ?int
    {
        return $this->marks[$subject] ?? null;
    }
}
