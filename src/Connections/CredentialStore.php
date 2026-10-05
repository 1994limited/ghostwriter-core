<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use SensitiveParameter;

/**
 * Where a site keeps what was set up on the Connections page: each
 * service's pasted (or connected) key, a note when a key stopped working,
 * and paid libraries' account tokens. By name: a service's id (`pexels`),
 * `health:<id>`, `tokens:<library>`.
 *
 * The addon implements it and **encrypts every value at rest**: Laravel's
 * Crypt (the APP_KEY) on Statamic and Filament, Craft's security component
 * (the security key) on Craft. Never in a file that is committed (project
 * config, content, addon settings YAML), never logged or shown. A value
 * that can't be decrypted (the key changed) reads as none.
 *
 * Tests\Contracts\CredentialStoreContract is what each implementation must do.
 */
interface CredentialStore
{
    /**
     * @return array<string, mixed>|null Null when nothing is kept.
     */
    public function get(string $name): ?array;

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $name, #[SensitiveParameter] array $value): void;

    public function forget(string $name): void;
}
