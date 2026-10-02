<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * A named lock, held across every process and server the site runs on,
 * for the per-session lock (E7, F2, F3) and any other read-change-write
 * two requests or a request and a job could do at once. Each addon
 * implements it with what its host has: Laravel's cache lock
 * (`Cache::lock($key, 30)->block($wait, $work)`), Craft's mutex
 * (`Craft::$app->getMutex()`), a file lock (`flock`).
 *
 * The work must run while the lock is held, and the lock must be let go
 * however the work ends. A lock that can't be had within the wait throws
 * LockTimeout. A lock taken again by the same process while held (the
 * work locking the same key) must not wait on itself: run the work, or
 * throw, but never deadlock.
 */
interface Lock
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws LockTimeout
     */
    public function run(string $key, callable $work, int $waitSeconds = 15): mixed;
}
