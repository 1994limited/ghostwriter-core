<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The provider is busy or down for a moment (529 or 503), after retries.
 */
class Overloaded extends ProviderException
{
    public function retryable(): bool
    {
        return true;
    }
}
