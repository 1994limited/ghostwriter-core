<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * Nothing by that ID, or nothing this person may see by it.
 */
final class NotFound extends Refused
{
    public function __construct(string $message = 'No such piece of writing.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 404;
    }
}
