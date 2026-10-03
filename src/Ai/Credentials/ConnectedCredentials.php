<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;

/**
 * The keys to call with: the environment's, and for providers a site can
 * connect (OpenRouter), the connected key when the environment has none.
 * A key in the environment always wins.
 *
 *     $credentials = new ConnectedCredentials($envCredentials, $providerKeys);
 *     $providers = new Providers($credentials, $http, $settings, $logger);
 */
final class ConnectedCredentials implements Credentials
{
    /** Providers whose key can come from Connect. */
    public const CONNECTABLE = ['openrouter'];

    /**
     * @param  array<int, string>  $connectable
     */
    public function __construct(
        private readonly Credentials $environment,
        private readonly ProviderKeys $keys,
        private readonly array $connectable = self::CONNECTABLE,
    ) {}

    public function key(string $provider): ?string
    {
        return $this->environment->key($provider) ?? $this->connectedKey($provider);
    }

    /** Where the key in use comes from: 'env', 'connected', or null for none. */
    public function source(string $provider): ?string
    {
        return match (true) {
            $this->environment->key($provider) !== null => 'env',
            $this->connectedKey($provider) !== null => 'connected',
            default => null,
        };
    }

    /** The environment's keys alone. */
    public function environment(): Credentials
    {
        return $this->environment;
    }

    private function connectedKey(string $provider): ?string
    {
        if (! in_array($provider, $this->connectable, true)) {
            return null;
        }

        $key = $this->keys->get($provider);

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
