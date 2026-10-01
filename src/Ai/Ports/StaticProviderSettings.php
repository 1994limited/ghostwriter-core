<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

/**
 * Provider settings held as plain values. For tests, and for hosts that
 * read their settings from config once.
 */
final class StaticProviderSettings implements ProviderSettings
{
    /**
     * @param  array<string, string|null>  $baseUrls  By provider.
     */
    public function __construct(
        public string $textProvider = 'anthropic',
        public ?string $textModel = null,
        public ?string $imageProvider = null,
        public ?string $imageModel = null,
        public int $timeout = 300,
        public array $baseUrls = [],
        public bool $anthropicFallbacks = true,
    ) {}

    public function textProvider(): string
    {
        return $this->textProvider;
    }

    public function textModel(): ?string
    {
        return $this->textModel;
    }

    public function imageProvider(): ?string
    {
        return $this->imageProvider;
    }

    public function imageModel(): ?string
    {
        return $this->imageModel;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function baseUrl(string $provider): ?string
    {
        $url = $this->baseUrls[$provider] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function anthropicFallbacks(): bool
    {
        return $this->anthropicFallbacks;
    }
}
