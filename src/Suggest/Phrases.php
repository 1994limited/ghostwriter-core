<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * The phrase lists the free checks read for one language
 * (`resources/suggest/phrases/{language}.php`). Core ships English, German,
 * French, Dutch and Spanish. A site in another language gets only the
 * checks that need no words: links, alt text, SEO length, overlaps, date
 * fields and age.
 *
 * The lists are patterns matched against normalised text (NormalisedText:
 * straight quotes, "-" for every dash, one space between words), case
 * insensitively. See en.php for what each list holds.
 */
final class Phrases
{
    public const LANGUAGES = ['en', 'de', 'fr', 'nl', 'es'];

    /** @var array<string, self|null> */
    private static array $loaded = [];

    /**
     * @param  list<string>  $current
     * @param  list<string>  $history
     * @param  list<string>  $relative
     * @param  list<string>  $closing
     * @param  array<string, int>  $months
     * @param  list<string>  $counts
     * @param  array<string, int>  $numbers
     * @param  list<string>  $linkText
     * @param  list<string>  $stopWords  Words too common to match pages on (articles, prepositions…), lower case.
     * @param  list<string>  $utilitySlugs  Address segments of pages never linked to (search, cart, thank-you…).
     */
    public function __construct(
        public readonly string $language,
        public readonly array $current,
        public readonly array $history,
        public readonly array $relative,
        public readonly array $closing,
        public readonly array $months,
        public readonly array $counts,
        public readonly array $numbers,
        public readonly array $linkText,
        public readonly int $longSentence,
        public readonly array $stopWords = [],
        public readonly array $utilitySlugs = [],
    ) {}

    /**
     * The lists for a language or locale ("en", "en_GB", "de-DE"); null
     * when core has none for it.
     */
    public static function for(string $locale): ?self
    {
        $language = self::language($locale);

        if (! array_key_exists($language, self::$loaded)) {
            self::$loaded[$language] = in_array($language, self::LANGUAGES, true) ? self::load($language) : null;
        }

        return self::$loaded[$language];
    }

    /** The language of a locale: "en_GB" and "EN-gb" are "en". */
    public static function language(string $locale): string
    {
        $parts = preg_split('/[_\-.@]/', trim($locale));

        return strtolower($parts === false ? $locale : $parts[0]);
    }

    public static function file(string $language): string
    {
        return dirname(__DIR__, 2).'/resources/suggest/phrases/'.$language.'.php';
    }

    /** One alternation of every number word, longest first, for {n}. */
    public function numberWords(): string
    {
        $words = array_keys($this->numbers);
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words));
    }

    /** One alternation of every month name, longest first. */
    public function monthNames(): string
    {
        $names = array_keys($this->months);
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $name) => preg_quote($name, '/'), $names));
    }

    /** A number as written, in digits or as a word; null when it's neither. */
    public function number(string $written): ?int
    {
        $written = mb_strtolower(trim($written));

        if (isset($this->numbers[$written])) {
            return $this->numbers[$written];
        }

        $digits = (string) preg_replace('/[^\d]/', '', $written);

        return $digits !== '' && strlen($digits) < 10 ? (int) $digits : null;
    }

    private static function load(string $language): ?self
    {
        $file = self::file($language);

        if (! is_file($file)) {
            return null;
        }

        $lists = require $file;

        if (! is_array($lists)) {
            return null;
        }

        $strings = fn (string $key) => array_values(array_filter(is_array($lists[$key] ?? null) ? $lists[$key] : [], 'is_string'));
        $numbers = function (string $key) use ($lists): array {
            $map = [];

            foreach (is_array($lists[$key] ?? null) ? $lists[$key] : [] as $word => $number) {
                if (is_string($word) && is_int($number)) {
                    $map[$word] = $number;
                }
            }

            return $map;
        };

        return new self(
            $language,
            $strings('current'),
            $strings('history'),
            $strings('relative'),
            $strings('closing'),
            $numbers('months'),
            $strings('counts'),
            $numbers('numbers'),
            $strings('link_text'),
            is_int($lists['long_sentence'] ?? null) ? $lists['long_sentence'] : 30,
            array_map('mb_strtolower', $strings('stop_words')),
            array_map('mb_strtolower', $strings('utility_slugs')),
        );
    }
}
