<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use SensitiveParameter;

/**
 * Ai\Ports\ProviderKeys over the Connections store, so the key Connect
 * with OpenRouter gets lands where a pasted one does, and shows on the
 * same card:
 *
 *     new OpenRouterConnection($environmentCredentials, new StoredProviderKeys($connections), $http, …);
 */
final class StoredProviderKeys implements ProviderKeys
{
    public function __construct(private readonly Connections $connections) {}

    public function get(string $provider): ?string
    {
        return $this->connections->stored($provider)[Field::KEY] ?? null;
    }

    public function put(string $provider, #[SensitiveParameter] string $key): void
    {
        $this->connections->store()->put($provider, ['fields' => [Field::KEY => trim($key)], 'saved_at' => (new \DateTimeImmutable)->format(DATE_ATOM), 'via' => 'connect']);
        $this->connections->markWorking($provider);
    }

    public function forget(string $provider): void
    {
        $this->connections->store()->forget($provider);
        $this->connections->markWorking($provider);
    }
}
