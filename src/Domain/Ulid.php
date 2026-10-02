<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * A ULID (26 characters of Crockford's base 32: a millisecond timestamp,
 * then randomness), the IDs Statamic's records and Filament's sessions use.
 * Written here so core needs no UID library.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $milliseconds = null): string
    {
        $time = $milliseconds ?? (int) floor(microtime(true) * 1000);
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out = self::ALPHABET[$time % 32].$out;
            $time = intdiv($time, 32);
        }

        foreach (str_split(random_bytes(16)) as $i => $byte) {
            if ($i >= 16) {
                break;
            }

            $out .= self::ALPHABET[ord($byte) % 32];
        }

        return $out;
    }
}
