<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;

/**
 * The word shingles of a paragraph, for Overlaps: every run of SIZE words,
 * lower-cased, as a 32-bit hash. An addon computes them when an entry is
 * saved, so EntryIndex can find paragraphs that share them without
 * reading every entry.
 */
final class Shingles
{
    public const SIZE = 5;

    /** Paragraphs shorter than this, in words, are never compared. */
    public const MIN_WORDS = 12;

    /**
     * @return list<int> Unique, sorted.
     */
    public static function of(string $text): array
    {
        $words = NormalisedText::words($text);
        $hashes = [];

        for ($i = 0; $i + self::SIZE <= count($words); $i++) {
            $hashes[crc32(implode(' ', array_slice($words, $i, self::SIZE)))] = true;
        }

        $hashes = array_keys($hashes);
        sort($hashes);

        return $hashes;
    }

    /**
     * The share of $of's shingles that $in has too, from 0 to 1.
     *
     * @param  array<int, int>  $of
     * @param  array<int, int>  $in
     */
    public static function share(array $of, array $in): float
    {
        if ($of === []) {
            return 0.0;
        }

        return count(array_intersect($of, $in)) / count($of);
    }
}
