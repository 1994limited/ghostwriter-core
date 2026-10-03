<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use SensitiveParameter;

/**
 * ProviderKeys in memory, for tests and demos. An addon's real store
 * encrypts them.
 */
final class InMemoryProviderKeys implements ProviderKeys
{
    /** @var array<string, string> */
    public array $keys = [];

    public function get(string $provider): ?string
    {
        return $this->keys[$provider] ?? null;
    }

    public function put(string $provider, #[SensitiveParameter] string $key): void
    {
        $this->keys[$provider] = $key;
    }

    public function forget(string $provider): void
    {
        unset($this->keys[$provider]);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['providers' => array_keys($this->keys)];
    }
}
