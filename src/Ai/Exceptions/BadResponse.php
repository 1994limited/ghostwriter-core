<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

/**
 * The provider sent back something unusable (not JSON, missing fields),
 * answered with an error not covered by another exception, or the request
 * was too large to send.
 */
class BadResponse extends ProviderException
{
    /**
     * @param  bool  $retryable  A server error (5xx) that outlasted the retries may still work later.
     */
    public function __construct(
        string $message,
        string $provider = '',
        ?int $status = null,
        ?\Throwable $previous = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message, $provider, $status, $previous);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
