<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The provider could not be reached, or took longer than the timeout.
 */
class Unreachable extends ProviderException
{
    public function retryable(): bool
    {
        return true;
    }
}
