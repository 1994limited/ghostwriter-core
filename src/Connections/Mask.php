<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use SensitiveParameter;

/**
 * How a kept key is shown: never whole, only its last four characters
 * ("••a1b2"), enough to match it to the key on the service's own page. A
 * key too short to give four away safely shows as "••" alone.
 */
final class Mask
{
    public static function ending(#[SensitiveParameter] string $key): string
    {
        $key = trim($key);

        return strlen($key) >= 12 ? '••'.substr($key, -4) : '••';
    }

    /**
     * A short, one-way mark of a key, to tell whether the key a note was
     * made about is still the one in use. Kept only inside the encrypted
     * store.
     */
    public static function fingerprint(#[SensitiveParameter] string $key): string
    {
        return substr(hash('sha256', trim($key)), 0, 16);
    }
}
