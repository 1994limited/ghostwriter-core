<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Finds a quote in a text, the same way wherever Ghostwriter points at
 * words: a comment on a range (page preview), a suggestion (Suggest
 * edits), and the front end's port of it (tested against the same cases,
 * resources/anchor/quote-cases.json).
 *
 * In order:
 * 1. **Exact**, after NormalisedText's normalising (whitespace, NBSP,
 *    curly quotes, dashes; with $markdown, markdown's inline syntax too).
 * 2. **Repeats picked by context.** Where the words are there more than
 *    once, the occurrence whose surroundings agree most with the prefix
 *    and suffix wins. With no context to go on, `$occurrence` picks one;
 *    without that either, it's ambiguous: null.
 * 3. **One fuzzy match**, when there's no exact one: a span of whole
 *    words whose character trigrams agree with the quote's at least 0.9
 *    (Dice), for quotes of at least 16 characters. Two separate fuzzy
 *    matches are ambiguous: null.
 */
final class QuoteFinder
{
    public const FUZZY = 0.9;

    /** Shorter quotes are matched exactly or not at all. */
    public const FUZZY_MIN = 16;

    public function find(TextQuote $quote, string $text, ?int $occurrence = null, bool $markdown = false): ?QuoteMatch
    {
        $haystack = NormalisedText::of($text, $markdown);
        $needle = NormalisedText::string($quote->exact, $markdown);

        if ($needle === '' || $haystack->text === '') {
            return null;
        }

        $found = $this->exact($haystack->text, $needle);

        if ($found !== []) {
            $index = $this->pick($found, $haystack->text, mb_strlen($needle), $quote, $markdown, $occurrence);

            if ($index === null) {
                return null;
            }

            [$offset, $length] = $haystack->original($found[$index], mb_strlen($needle));

            return new QuoteMatch($offset, $length, $index);
        }

        return $this->fuzzy($haystack, $needle);
    }

    /**
     * Every start of the needle in the haystack, in characters.
     *
     * @return list<int>
     */
    private function exact(string $haystack, string $needle): array
    {
        $found = [];
        $from = 0;

        while (($at = mb_strpos($haystack, $needle, $from)) !== false) {
            $found[] = $at;
            $from = $at + 1;
        }

        return $found;
    }

    /**
     * @param  non-empty-list<int>  $found
     */
    private function pick(array $found, string $haystack, int $length, TextQuote $quote, bool $markdown, ?int $occurrence): ?int
    {
        if (count($found) === 1) {
            return 0;
        }

        $prefix = NormalisedText::string($quote->prefix, $markdown);
        $suffix = NormalisedText::string($quote->suffix, $markdown);

        if ($prefix !== '' || $suffix !== '') {
            $scores = [];

            foreach ($found as $i => $at) {
                $before = mb_substr($haystack, max(0, $at - mb_strlen($prefix) - 1), min($at, mb_strlen($prefix) + 1));
                $after = mb_substr($haystack, $at + $length, mb_strlen($suffix) + 1);
                $scores[$i] = self::commonSuffix(trim($before), trim($prefix)) + self::commonPrefix(trim($after), trim($suffix));
            }

            arsort($scores);
            $best = array_key_first($scores);
            $values = array_values($scores);

            if ($values[0] > 0 && ($values[1] ?? -1) < $values[0]) {
                return $best;
            }
        }

        return $occurrence !== null && isset($found[$occurrence]) ? $occurrence : null;
    }

    private function fuzzy(NormalisedText $haystack, string $needle): ?QuoteMatch
    {
        $length = mb_strlen($needle);

        if ($length < self::FUZZY_MIN) {
            return null;
        }

        $target = self::trigrams($needle);
        $text = $haystack->text;

        // Word starts and ends, in characters.
        preg_match_all('/\S+/u', $text, $words, PREG_OFFSET_CAPTURE);
        $spans = [];

        foreach ($words[0] as [$word, $byte]) {
            $start = mb_strlen(substr($text, 0, $byte));
            $bare = mb_strlen((string) preg_replace('/[\p{P}\p{S}]+$/u', '', $word));
            // A span may end after a word's closing punctuation, or before it.
            $spans[] = [$start, array_unique([$start + mb_strlen($word), $start + max(1, $bare)])];
        }

        $candidates = [];

        foreach ($spans as $i => [$start]) {
            for ($j = $i; $j < count($spans); $j++) {
                foreach ($spans[$j][1] as $end) {
                    $size = $end - $start;

                    if ($size < $length * 0.75 || $size > $length * 1.25) {
                        continue;
                    }

                    $score = self::dice($target, self::trigrams(mb_substr($text, $start, $size)));

                    if ($score >= self::FUZZY) {
                        $candidates[] = [$start, $size, $score];
                    }
                }

                if ($spans[$j][1][0] - $start > $length * 1.25) {
                    break;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Overlapping candidates are one place; keep the best of each.
        usort($candidates, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $places = [];

        foreach ($candidates as $candidate) {
            $last = array_key_last($places);

            if ($last !== null && $candidate[0] < $places[$last][0] + $places[$last][1]) {
                if ($candidate[2] > $places[$last][2]) {
                    $places[$last] = $candidate;
                }

                continue;
            }

            $places[] = $candidate;
        }

        if (count($places) !== 1) {
            return null;
        }

        [$offset, $size] = $haystack->original($places[0][0], $places[0][1]);

        return new QuoteMatch($offset, $size, 0, true);
    }

    /**
     * @return array<string, int>
     */
    private static function trigrams(string $text): array
    {
        $chars = mb_str_split(' '.mb_strtolower($text).' ');
        $grams = [];

        for ($i = 0; $i + 2 < count($chars); $i++) {
            $gram = $chars[$i].$chars[$i + 1].$chars[$i + 2];
            $grams[$gram] = ($grams[$gram] ?? 0) + 1;
        }

        return $grams;
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     */
    private static function dice(array $a, array $b): float
    {
        $shared = 0;

        foreach ($a as $gram => $count) {
            $shared += min($count, $b[$gram] ?? 0);
        }

        $total = array_sum($a) + array_sum($b);

        return $total === 0 ? 0.0 : 2 * $shared / $total;
    }

    private static function commonSuffix(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = 0;

        while ($n < count($a) && $n < count($b) && $a[count($a) - 1 - $n] === $b[count($b) - 1 - $n]) {
            $n++;
        }

        return $n;
    }

    private static function commonPrefix(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = 0;

        while ($n < count($a) && $n < count($b) && $a[$n] === $b[$n]) {
            $n++;
        }

        return $n;
    }
}
