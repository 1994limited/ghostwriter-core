<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * Not now, or not from this state: nothing to try again, an idea that
 * isn't dismissed being put back, an image already being made.
 */
class Conflict extends Refused
{
    public function status(): int
    {
        return 409;
    }
}
