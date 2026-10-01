<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A model call that did not work. The message is written to be shown to the
 * person who asked, so it says what happened in plain words, and never
 * holds a key.
 *
 * Catch this to catch every failure; the subclasses say what kind it was,
 * and retryable() whether trying again later could help.
 */
class ProviderException extends RuntimeException
{
    /**
     * @param  string  $provider  anthropic, openai, gemini, fake, or '' when not known.
     * @param  int|null  $status  The HTTP status the provider answered with, where there was one.
     */
    public function __construct(
        string $message,
        private readonly string $provider = '',
        private readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /** Whether the same call could work if tried again later. */
    public function retryable(): bool
    {
        return false;
    }
}
