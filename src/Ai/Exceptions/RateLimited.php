<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The provider is limiting requests (429), after retries.
 */
class RateLimited extends ProviderException
{
    public function retryable(): bool
    {
        return true;
    }
}
