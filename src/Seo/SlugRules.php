<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * A page's address from its title (SEO layer §10, decision 13), with no
 * model:
 *
 * 1. Text\Slug::make() on the title: facts still to add left out, counts
 *    still to confirm as the value they mark.
 * 2. The language's stop words taken out (Suggest\Phrases), unless that
 *    leaves fewer than two words. A leading negation stays ("no-dig").
 * 3. A year taken out, unless the group is dated (a journal) or the
 *    address would clash without it: an evergreen page shouldn't carry
 *    "2026" in its address for ever.
 * 4. At most five words and 50 characters, cut between words.
 * 5. Unique among $taken, with `-2`, `-3`…
 *
 *     SlugRules::suggest('How to prune a walled garden in winter', 'en');   // "prune-walled-garden-winter"
 *
 * Slugs are only ever set on entries never published; nothing here knows
 * or decides that (the addons do).
 */
final class SlugRules
{
    public const MAX_WORDS = 5;

    public const MAX_LENGTH = 50;

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

        $words = self::withoutStopWords($words, $language);
        $withYears = $words;

        if (! $dated) {
            $without = array_values(array_filter($words, fn (string $word) => ! self::isYear($word)));
            $words = $without !== [] ? $without : $words;
        }

        $slug = self::fit($words);

        // The year comes back when the address is only free with it.
        if (! $dated && in_array($slug, $taken, true) && $withYears !== $words) {
            $withYear = self::fit($withYears);
            $slug = in_array($withYear, $taken, true) ? $slug : $withYear;
        }

        return self::unique($slug, $taken);
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
