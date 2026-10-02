<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

use RuntimeException;

/**
 * A rule said no. Each kind carries the HTTP status an addon's controller
 * would answer with (`status()`), and a message fit to show.
 */
abstract class Refused extends RuntimeException
{
    abstract public function status(): int;
}
