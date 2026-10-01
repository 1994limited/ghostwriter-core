<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;

/**
 * A model that writes, and can look at images while it does.
 */
interface TextProvider
{
    /** anthropic, openai, gemini or fake. */
    public function handle(): string;

    /**
     * Providers never throw because a reply was cut off: they report it as
     * StopReason::MaxTokens and leave the decision to the caller.
     *
     * @throws ProviderException when the call fails or the model declines.
     */
    public function text(TextRequest $request): TextResponse;
}
