<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * This person may not do that: open someone else's private conversation,
 * delete a shared piece they didn't start, use another person's image
 * request.
 */
final class NotAllowed extends Refused
{
    public function status(): int
    {
        return 403;
    }
}
