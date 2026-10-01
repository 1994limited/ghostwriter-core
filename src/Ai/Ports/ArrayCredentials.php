<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

/**
 * Keys held in an array, by provider. For tests, and for hosts that have
 * already read their keys from config.
 */
final class ArrayCredentials implements Credentials
{
    /**
     * @param  array<string, string|null>  $keys
     */
    public function __construct(private array $keys = []) {}

    public function key(string $provider): ?string
    {
        $key = $this->keys[$provider] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function set(string $provider, ?string $key): self
    {
        $this->keys[$provider] = $key;

        return $this;
    }
}
