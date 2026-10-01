<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

/**
 * Runs a pattern in UTF-8 mode, and in byte mode when the subject is not
 * valid UTF-8.
 *
 * The three addons drifted here: one ran these patterns with the `u`
 * modifier, two without. With valid UTF-8 the answers agree, except that `u`
 * also lets `\s` match Unicode spaces. With invalid UTF-8 a `u` pattern
 * fails outright, which turned a draft with one bad byte into an empty one.
 * Trying `u` first and falling back to bytes keeps the better of both.
 *
 * @internal
 */
final class Pcre
{
    /**
     * @param  array<int|string, string>|null  $matches
     *
     * @param-out array<int|string, string> $matches
     */
    public static function match(string $pattern, string $subject, ?array &$matches = null): bool
    {
        $result = @preg_match($pattern.'u', $subject, $matches);

        if ($result === false) {
            $result = preg_match($pattern, $subject, $matches);
        }

        return $result === 1;
    }

    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = @preg_replace($pattern.'u', $replacement, $subject);

        if ($result === null) {
            $result = preg_replace($pattern, $replacement, $subject);
        }

        return (string) $result;
    }
}
