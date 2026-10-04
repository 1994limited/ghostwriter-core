<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

/**
 * What the phrase checks share.
 *
 * @internal
 */
trait ReadsText
{
    /**
     * One pattern from a list of fragments, whole words only, case
     * insensitive, with placeholders filled.
     *
     * @param  array<int, string>  $fragments
     * @param  array<string, string>  $fill
     */
    private static function alternation(array $fragments, array $fill = []): string
    {
        $parts = array_map(fn (string $fragment) => '(?:'.strtr($fragment, $fill).')', array_values($fragments));

        return '/(?<![\p{L}\p{N}])(?:'.implode('|', $parts).')(?![\p{L}\p{N}])/iu';
    }

    /**
     * Whether a match is written as history: a `history` pattern matches
     * text around it that includes it.
     *
     * @param  array<int, string>  $history
     * @param  array<string, string>  $fill
     */
    private static function isHistory(string $plain, int $offset, int $length, array $history, array $fill): bool
    {
        if ($history === []) {
            return false;
        }

        $start = max(0, $offset - 40);
        $window = mb_substr($plain, $start, $offset - $start + $length + 40);

        return preg_match(self::alternation(array_map(fn (string $fragment) => strtr($fragment, array_map(fn (string $value) => preg_quote($value, '/'), $fill)), $history)), $window) === 1;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $taken
     */
    private static function overlaps(array $taken, int $offset, int $length): bool
    {
        foreach ($taken as [$at, $size]) {
            if ($offset < $at + $size && $at < $offset + $length) {
                return true;
            }
        }

        return false;
    }
}
