<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * A word diff of two texts, for "Before / after": runs of words kept
 * (`=`), taken out (`-`) and put in (`+`), with the whitespace and
 * punctuation that go with them, so joining the `=` and `-` runs gives the
 * before and the `=` and `+` runs the after.
 */
final class WordDiff
{
    /** Texts longer than this many words are shown as one change. */
    public const MAX_WORDS = 1500;

    /**
     * @return list<array{0: '='|'-'|'+', 1: string}>
     */
    public static function diff(string $before, string $after): array
    {
        $a = self::tokens($before);
        $b = self::tokens($after);

        if (count($a) > self::MAX_WORDS || count($b) > self::MAX_WORDS) {
            return array_values(array_filter([['-', $before], ['+', $after]], fn (array $run) => $run[1] !== ''));
        }

        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $runs = [];
        $i = 0;
        $j = 0;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $runs = self::push($runs, '=', $a[$i]);
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $runs = self::push($runs, '+', $b[$j++]);
            } else {
                $runs = self::push($runs, '-', $a[$i++]);
            }
        }

        return $runs;
    }

    /**
     * Words with the whitespace after them.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /**
     * @param  list<array{0: '='|'-'|'+', 1: string}>  $runs
     * @param  '='|'-'|'+'  $op
     * @return list<array{0: '='|'-'|'+', 1: string}>
     */
    private static function push(array $runs, string $op, string $token): array
    {
        $last = array_pop($runs);

        if ($last !== null && $last[0] === $op) {
            return [...$runs, [$op, $last[1].$token]];
        }

        return $last === null ? [[$op, $token]] : [...$runs, $last, [$op, $token]];
    }
}
