<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Figures;

/**
 * Facts in new text that none of its sources has. Whatever writes words
 * from words someone gave (an extra prepared with a draft, a revision from
 * a comment, a suggested edit) must not add a number, a date, an amount,
 * a quotation or a name of its own. No model is involved.
 *
 * What counts as a fact in the new text:
 * - **a figure**: every number written in digits, with its currency or
 *   unit, compared by what it says (Studio\Figures): "4" is given by
 *   "four", "£1,200" by "£1.2k", "14 May 2026" by "2026-05-14";
 * - **a quotation**: words inside quotation marks ("…", “…”), which must
 *   appear in a source as they are (after normalising);
 * - **a name**: a capitalised word, or a run of them ("Tyne Valley"), that
 *   doesn't start a sentence, a line or a heading, compared without case.
 *   Lines written in title case (three words or more, three in four capitalised) are left out: their capitals say nothing.
 *
 * `[[ask: …]]` markers, link targets and HTML tags are not facts.
 */
final class SourceCheck
{
    /** Capitalised words that are never names. */
    private const NOT_NAMES = ['I', 'OK', 'A', 'An', 'The'];

    /**
     * Each fact in $new that appears in none of $sources, as written in
     * $new, in order and once each. Empty when everything is sourced.
     *
     * @param  array<int|string, string>  $sources
     * @return list<string>
     */
    public function unsourced(string $new, array $sources): array
    {
        $new = self::plain($new);
        $source = implode("\n\n", array_map(fn (string $text) => self::plain($text), $sources));
        $missing = [];

        $known = Figures::known($source);

        foreach (Figures::find($new) as $figure) {
            if (! Figures::given($figure['values'], $known)) {
                $missing[] = trim($figure['figure']);
            }
        }

        $haystack = mb_strtolower(NormalisedText::string($source));

        foreach (self::quotations($new) as $quotation) {
            if (! str_contains($haystack, mb_strtolower(NormalisedText::string($quotation)))) {
                $missing[] = $quotation;
            }
        }

        $words = ' '.implode(' ', NormalisedText::words($source)).' ';

        foreach (self::names($new) as $name) {
            if (! str_contains($words, ' '.implode(' ', NormalisedText::words($name)).' ')) {
                $missing[] = $name;
            }
        }

        return array_values(array_unique($missing));
    }

    /** Text with markers, link targets and tags taken out. */
    private static function plain(string $text): string
    {
        $text = Markers::withoutAsks($text);
        $text = (string) preg_replace('/\]\([^)\n]*\)/u', ']', $text);
        $text = (string) preg_replace('/<[^>\n]*>/u', ' ', $text);

        return (string) preg_replace('/\bhttps?:\/\/\S+/iu', ' ', $text);
    }

    /**
     * @return list<string>
     */
    private static function quotations(string $text): array
    {
        preg_match_all('/“([^”\n]{2,300})”|"([^"\n]{2,300})"/u', $text, $matches, PREG_SET_ORDER);

        return array_values(array_map(fn (array $match) => trim(($match[2] ?? '') !== '' ? $match[2] : ($match[1] ?? '')), $matches));
    }

    /**
     * Runs of capitalised words that don't start a sentence or a line.
     *
     * @return list<string>
     */
    private static function names(string $text): array
    {
        $names = [];

        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            $line = (string) preg_replace('/^\s*(?:#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)*/u', '', $line);
            $words = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === []) {
                continue;
            }

            $capitalised = count(array_filter($words, fn (string $word) => preg_match('/^\W*\p{Lu}/u', $word) === 1));

            if (count($words) >= 3 && $capitalised / count($words) >= 0.75) {
                continue;
            }

            $run = [];
            $sentenceStart = true;

            foreach ($words as $word) {
                $bare = trim($word, "\"'“”‘’()[]*_,;:.!?…");
                $isName = ! $sentenceStart
                    && preg_match('/^\p{Lu}[\p{L}\p{M}\'’-]*$/u', $bare) === 1
                    && ! in_array($bare, self::NOT_NAMES, true);

                if ($isName) {
                    $run[] = $bare;
                } elseif ($run !== []) {
                    $names[] = implode(' ', $run);
                    $run = [];
                }

                $sentenceStart = preg_match('/[.!?…:]["\'”’)*_]*$/u', $word) === 1 || preg_match('/^\W*$/u', $word) === 1;

                // A name ends at its own punctuation: "Durham, Tyne Valley".
                if ($run !== [] && preg_match('/[,;:.!?…)]["\'”’*_]*$/u', $word) === 1) {
                    $names[] = implode(' ', $run);
                    $run = [];
                }
            }

            if ($run !== []) {
                $names[] = implode(' ', $run);
            }
        }

        return $names;
    }
}
