<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Whether a replacement for a quoted range still makes a complete sentence
 * where it goes, with no model: a safety net after the model has written
 * it (Suggest edits). The text is one block (a paragraph, heading, list
 * item or a short value) in plain form, and the range is in characters.
 *
 * - A quote that starts a sentence (the start of the block, or after
 *   `.`, `!`, `?` or `…` and a space) with a capital needs a replacement
 *   that starts with a capital, a digit or a quote mark.
 * - A quote that ends with sentence-ending punctuation needs a
 *   replacement that does too. One that doesn't, with the sentence going
 *   on after it, needs a replacement that doesn't end the sentence early.
 * - No word is doubled where the replacement meets the text around it
 *   ("we will will visit"), unless the text already had it.
 * - A replacement with no words is only for a whole sentence, unless
 *   `$mayBeEmpty` (a Duplicate may shrink to nothing).
 */
final class SentenceFit
{
    private const END = '/[.!?…]["\'’”)\]*_]*$/u';

    private const WORD = '[\p{L}\p{N}][\p{L}\p{N}\'’-]*';

    /**
     * Why it doesn't fit (`start`, `end`, `doubled`, `empty`), or null
     * when it does.
     */
    public static function problem(string $block, int $offset, int $length, string $replacement, bool $mayBeEmpty = false): ?string
    {
        $before = mb_substr($block, 0, $offset);
        $quote = trim(mb_substr($block, $offset, $length));
        $after = mb_substr($block, $offset + $length);
        $new = trim(NormalisedText::string($replacement, true));

        $startsSentence = trim($before) === '' || preg_match('/[.!?…]["\'’”)\]*_]*\s+$/u', $before) === 1;
        $endsSentence = preg_match(self::END, $quote) === 1 || trim($after) === '' || preg_match('/^\s*[.!?…]/u', $after) === 1;

        if ($new === '') {
            return $mayBeEmpty || ($startsSentence && $endsSentence) ? null : 'empty';
        }

        if ($startsSentence && preg_match('/^\p{Lu}/u', $quote) === 1 && preg_match('/^[\p{Lu}\p{N}"\'“‘«„(\[*_]/u', $new) !== 1) {
            return 'start';
        }

        if (preg_match(self::END, $quote) === 1) {
            if (preg_match(self::END, $new) !== 1) {
                return 'end';
            }
        } elseif ((! $endsSentence || preg_match('/^[.!?…]/u', $after) === 1) && preg_match(self::END, $new) === 1) {
            // Ends the sentence early, or twice ("£450..").
            return 'end';
        }

        $lastBefore = preg_match('/('.self::WORD.')\s+$/u', $before, $m) === 1 ? mb_strtolower($m[1]) : null;
        $firstAfter = preg_match('/^\s+('.self::WORD.')/u', $after, $m) === 1 ? mb_strtolower($m[1]) : null;

        if ($lastBefore !== null && $lastBefore === self::firstWord($new) && $lastBefore !== self::firstWord($quote)) {
            return 'doubled';
        }

        if ($firstAfter !== null && $firstAfter === self::lastWord($new) && $firstAfter !== self::lastWord($quote)) {
            return 'doubled';
        }

        return null;
    }

    public static function fits(string $block, int $offset, int $length, string $replacement, bool $mayBeEmpty = false): bool
    {
        return self::problem($block, $offset, $length, $replacement, $mayBeEmpty) === null;
    }

    private static function firstWord(string $text): ?string
    {
        return preg_match('/^[^\p{L}\p{N}]*('.self::WORD.')/u', $text, $m) === 1 ? mb_strtolower($m[1]) : null;
    }

    private static function lastWord(string $text): ?string
    {
        return preg_match('/('.self::WORD.')[^\p{L}\p{N}]*$/u', $text, $m) === 1 ? mb_strtolower($m[1]) : null;
    }
}
