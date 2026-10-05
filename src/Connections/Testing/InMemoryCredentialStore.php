<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use SensitiveParameter;

/**
 * A CredentialStore in memory, for tests. Not encrypted: never use it on a site.
 */
final class InMemoryCredentialStore implements CredentialStore
{
    /** @var array<string, array<string, mixed>> */
    public array $values = [];

    public function get(string $name): ?array
    {
        return $this->values[$name] ?? null;
    }

    public function put(string $name, #[SensitiveParameter] array $value): void
    {
        $this->values[$name] = $value;
    }

    public function forget(string $name): void
    {
        unset($this->values[$name]);
    }
}
