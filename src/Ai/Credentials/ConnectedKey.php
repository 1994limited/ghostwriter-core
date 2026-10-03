<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

use SensitiveParameter;

/**
 * A key got by connecting a provider account. It is already kept through
 * Ports\ProviderKeys when connect() hands it back; the addon only needs
 * masked() for the settings row. Never logged: var_dump() and print_r()
 * show it masked, and it can't be serialized.
 */
final class ConnectedKey
{
    public function __construct(
        public readonly string $provider,
        #[SensitiveParameter] private readonly string $key,
    ) {}

    /** The key itself, to send to the provider. */
    public function key(): string
    {
        return $this->key;
    }

    /** Enough to recognise it on the provider's keys page: sk-or-v1-a…7f2. */
    public function masked(): string
    {
        return self::mask($this->key);
    }

    public static function mask(#[SensitiveParameter] string $key): string
    {
        if (strlen($key) < 16) {
            return '…';
        }

        $prefix = preg_match('/^(sk-[a-z]+-v\d+-|sk-[a-z]+-|sk-)/i', $key, $m) ? $m[1] : '';

        return $prefix.substr($key, strlen($prefix), 1).'…'.substr($key, -3);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'key' => $this->masked()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A connected key is not serialized; keep it through ProviderKeys.');
    }
}
