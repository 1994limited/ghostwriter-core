<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * What checking a key found: it works, or why not, as a Strings key and
 * its parameters. Never holds the key.
 */
final class CheckResult
{
    /**
     * @param  array<string, string>  $params
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {}

    public static function works(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, string>  $params
     */
    public static function fails(string $reason, array $params = []): self
    {
        return new self(false, $reason, $params);
    }

    public function message(?Strings $strings = null): ?string
    {
        return $this->reason === null ? null : ($strings ?? Strings::english())->get($this->reason, $this->params);
    }
}
