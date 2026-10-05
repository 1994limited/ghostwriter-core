<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * A page's address from its title (SEO layer §10, decision 13), with no
 * model:
 *
 * 1. Text\Slug::make() on the title: facts still to add left out, counts
 *    still to confirm as the value they mark.
 * 2. A year taken out, unless the group is dated (a journal) or the
 *    address would clash without it: an evergreen page shouldn't carry
 *    "2026" in its address for ever.
 * 3. The whole phrase is kept, so a question or a how-to reads as people
 *    search for it: "What to do in the garden in March" is
 *    `what-to-do-in-the-garden-in-march`. Only a long title (over
 *    LONG_TITLE characters) or an address over LONG_SLUG loses the
 *    language's stop words (Suggest\Phrases), unless that leaves fewer
 *    than two words, and is then cut to MAX_WORDS words and MAX_LENGTH
 *    characters, between words. A leading negation stays ("no-dig").
 * 4. Filler at either end goes (the language's `slug_filler`: articles,
 *    and prepositions and conjunctions left dangling): "The best roses
 *    for shade" is `best-roses-for-shade`.
 * 5. Unique among $taken, with `-2`, `-3`…
 *
 *     SlugRules::suggest('How to prune a walled garden in winter', 'en');   // "how-to-prune-a-walled-garden-in-winter"
 *
 * Slugs are only ever set on entries never published; nothing here knows
 * or decides that (the addons do).
 */
final class SlugRules
{
    /** A title longer than this, in characters, loses its stop words. */
    public const LONG_TITLE = 60;

    /** An address longer than this loses its stop words too. */
    public const LONG_SLUG = 75;

    /** A shortened address has at most this many words… */
    public const MAX_WORDS = 6;

    /** …and this many characters. */
    public const MAX_LENGTH = 60;

    private const NEGATIONS = ['no', 'non', 'not', 'kein', 'keine', 'nicht', 'sans', 'pas', 'geen', 'niet', 'sin'];

    /**
     * @param  list<string>  $taken  Slugs already used in the same scope (collection or section, and site).
     */
    public static function suggest(string $title, string $language = 'en', bool $dated = false, array $taken = []): string
    {
        $words = self::words($title);

        if ($words === []) {
            return '';
        }

        $long = mb_strlen(Slug::clip(Markers::withoutAsks($title), 1000)) > self::LONG_TITLE;
        $withYears = $words;

        if (! $dated) {
            $without = array_values(array_filter($words, fn (string $word) => ! self::isYear($word)));
            $words = $without !== [] ? $without : $words;
        }

        $slug = self::shape($words, $language, $long);

        // The year comes back when the address is only free with it.
        if (! $dated && in_array($slug, $taken, true) && $withYears !== $words) {
            $withYear = self::shape($withYears, $language, $long);
            $slug = in_array($withYear, $taken, true) ? $slug : $withYear;
        }

        return self::unique($slug, $taken);
    }

    /**
     * The whole phrase, or for a long title or address the phrase without
     * stop words and cut to size; filler off both ends either way.
     *
     * @param  list<string>  $words
     */
    private static function shape(array $words, string $language, bool $long): string
    {
        if (! $long && strlen(implode('-', $words)) <= self::LONG_SLUG) {
            return implode('-', self::withoutFiller($words, $language));
        }

        return self::fit(self::withoutFiller(self::withoutStopWords($words, $language), $language));
    }

    /**
     * An address an editor typed, cleaned as the CMS would: lower-case
     * ASCII words joined by hyphens, at most 100 characters. Stop words
     * stay: it is their choice.
     */
    public static function clean(string $slug): string
    {
        return Slug::make($slug, 100);
    }

    /**
     * @param  list<string>  $taken
     */
    public static function unique(string $slug, array $taken): string
    {
        if ($slug === '' || ! in_array($slug, $taken, true)) {
            return $slug;
        }

        for ($n = 2; ; $n++) {
            $candidate = $slug.'-'.$n;

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function words(string $title): array
    {
        $slug = Slug::make($title, 300);

        return $slug === '' ? [] : explode('-', $slug);
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    public static function withoutStopWords(array $words, string $language): array
    {
        $phrases = Phrases::for($language);

        if ($phrases === null || $phrases->stopWords === []) {
            return $words;
        }

        $stop = array_flip(array_map(fn (string $word) => Slug::make($word), $phrases->stopWords));
        $kept = [];

        foreach ($words as $i => $word) {
            if ($i === 0 && in_array($word, self::NEGATIONS, true) && count($words) > 1) {
                $kept[] = $word;

                continue;
            }

            if (! isset($stop[$word])) {
                $kept[] = $word;
            }
        }

        return count($kept) >= 2 ? $kept : $words;
    }

    /**
     * The words without the language's filler (`slug_filler`) at the start
     * and the end, never fewer than two words. A leading negation stays.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public static function withoutFiller(array $words, string $language): array
    {
        $phrases = Phrases::for($language);

        if ($phrases === null || $phrases->slugFiller === [] || count($words) < 3) {
            return $words;
        }

        $filler = array_flip(array_map(fn (string $word) => Slug::make($word), $phrases->slugFiller));

        while (count($words) > 2 && isset($filler[$words[0]]) && ! in_array($words[0], self::NEGATIONS, true)) {
            array_shift($words);
        }

        while (count($words) > 2 && isset($filler[$words[count($words) - 1]])) {
            array_pop($words);
        }

        return $words;
    }

    /**
     * @param  list<string>  $words
     */
    private static function fit(array $words): string
    {
        $words = array_slice($words, 0, self::MAX_WORDS);

        while (count($words) > 1 && strlen(implode('-', $words)) > self::MAX_LENGTH) {
            array_pop($words);
        }

        return substr(implode('-', $words), 0, self::MAX_LENGTH);
    }

    private static function isYear(string $word): bool
    {
        return preg_match('/^(?:19|20)\d\d$/', $word) === 1;
    }
}
