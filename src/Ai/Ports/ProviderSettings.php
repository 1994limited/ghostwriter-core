<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

/**
 * The choices a site makes about providers. Each addon implements this over
 * its settings or config; StaticProviderSettings holds them as plain values.
 */
interface ProviderSettings
{
    /** anthropic, openai, gemini or openrouter. */
    public function textProvider(): string;

    /** Null for the provider's default in Models. */
    public function textModel(): ?string;

    /** openai, gemini or openrouter, or null for the first in Models::IMAGE_ORDER that has a key. */
    public function imageProvider(): ?string;

    /** Null for the provider's default in Models. */
    public function imageModel(): ?string;

    /** Seconds each call may take. */
    public function timeout(): int;

    /**
     * A gateway that speaks the provider's own API, or null for the
     * provider's own address. Must be https://, except for localhost.
     */
    public function baseUrl(string $provider): ?string;

    /** Whether to send Anthropic's server-side fallbacks. Normally true. */
    public function anthropicFallbacks(): bool;
}
