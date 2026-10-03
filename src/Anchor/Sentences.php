<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Sentences and blocks in markdown, by character offsets: a scoped edit
 * may change only the sentence or sentences a quote is in (page preview's
 * text comments), and a quoted range must lie inside one paragraph,
 * heading, list item or table cell (Suggest edits).
 *
 * A sentence ends at `.`, `!`, `?` or `…` (and any closing quotes or
 * brackets) followed by a space, or at a line break. Common abbreviations
 * ("e.g.", "Mr.", "St.") and initials don't end one.
 */
final class Sentences
{
    private const ABBREVIATIONS = ['e.g', 'i.e', 'etc', 'mr', 'mrs', 'ms', 'dr', 'st', 'no', 'vs', 'approx', 'inc', 'ltd', 'co', 'jr', 'sr', 'prof', 'mt'];

    /**
     * The sentences of some text, as [offset, length] in characters,
     * without the spaces between them.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function split(string $text): array
    {
        $chars = mb_str_split($text);
        $count = count($chars);
        $sentences = [];
        $start = null;

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];

            if ($start === null) {
                if (trim($char) === '') {
                    continue;
                }

                $start = $i;
            }

            if ($char === "\n") {
                $sentences[] = self::span($chars, $start, $i);
                $start = null;

                continue;
            }

            if (in_array($char, ['.', '!', '?', '…'], true)) {
                $end = $i + 1;

                while ($end < $count && in_array($chars[$end], ['.', '!', '?', '…', '"', "'", '’', '”', ')', ']', '*', '_'], true)) {
                    $end++;
                }

                if (($end >= $count || $chars[$end] === ' ' || $chars[$end] === "\n" || $chars[$end] === "\u{00A0}") && ! ($char === '.' && self::abbreviation($chars, $start, $i))) {
                    $sentences[] = self::span($chars, $start, $end);
                    $start = null;
                    $i = $end - 1;
                }
            }
        }

        if ($start !== null) {
            $sentences[] = self::span($chars, $start, $count);
        }

        return array_values(array_filter($sentences, fn (array $span) => $span[1] > 0));
    }

    /**
     * The span from the start of the sentence a range starts in to the end
     * of the sentence it ends in, as [offset, length]. A range outside
     * every sentence comes back as it is.
     *
     * @return array{0: int, 1: int}
     */
    public static function covering(string $text, int $offset, int $length): array
    {
        $start = $offset;
        $end = $offset + max(1, $length);

        foreach (self::split($text) as [$at, $size]) {
            if ($offset >= $at && $offset < $at + $size) {
                $start = $at;
            }

            $last = $offset + max(1, $length) - 1;

            if ($last >= $at && $last < $at + $size) {
                $end = $at + $size;
            }
        }

        return [$start, max(0, $end - $start)];
    }

    /**
     * Whether a range lies inside one block of markdown: it crosses no
     * line break, and on a table row no cell's `|`.
     */
    public static function inOneBlock(string $markdown, int $offset, int $length): bool
    {
        $range = mb_substr($markdown, $offset, $length);

        if (str_contains($range, "\n")) {
            return false;
        }

        $lineStart = mb_strrpos(mb_substr($markdown, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = mb_strpos($markdown, "\n", $offset);
        $line = trim(mb_substr($markdown, $lineStart, ($lineEnd === false ? mb_strlen($markdown) : $lineEnd) - $lineStart));

        return ! (str_starts_with($line, '|') && str_contains(str_replace('\\|', '', $range), '|'));
    }

    /**
     * @param  list<string>  $chars
     * @return array{0: int, 1: int}
     */
    private static function span(array $chars, int $start, int $end): array
    {
        while ($end > $start && trim($chars[$end - 1]) === '') {
            $end--;
        }

        return [$start, $end - $start];
    }

    /**
     * @param  list<string>  $chars
     */
    private static function abbreviation(array $chars, int $start, int $dot): bool
    {
        $word = '';

        for ($i = $dot - 1; $i >= $start && trim($chars[$i]) !== ''; $i--) {
            $word = $chars[$i].$word;
        }

        $word = mb_strtolower(trim($word, '(["\'“‘*_'));

        // An initial: "J. Smith".
        if (mb_strlen($word) === 1 && preg_match('/^\p{Lu}$/u', $chars[$dot - 1] ?? '') === 1) {
            return true;
        }

        return in_array($word, self::ABBREVIATIONS, true);
    }
}
