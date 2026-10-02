<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Queue;

/**
 * Where the queue-waiting marks are kept: somewhere the worker and the web
 * request both see whatever cache the site uses (Statamic small files
 * under `storage/ghostwriter/queued`, Filament `queued:<subject>` state
 * rows). Subjects are short strings such as `session:01J…` or `image:01J…`.
 *
 * The rules a store must keep are in tests/Contracts/WaitingStoreContract.php.
 */
interface WaitingStore
{
    /**
     * Marks the subject as queued at the time given (a Unix timestamp),
     * replacing any mark it had.
     */
    public function mark(string $subject, int $at): void;

    public function unmark(string $subject): void;

    /**
     * When the subject was marked, or null when it isn't.
     */
    public function markedAt(string $subject): ?int;
}
