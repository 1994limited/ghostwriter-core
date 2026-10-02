<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * Someone else held the lock for longer than the wait.
 */
final class LockTimeout extends Conflict
{
    public function __construct(string $message = 'Ghostwriter is busy saving that. Try again in a moment.')
    {
        parent::__construct($message);
    }
}
