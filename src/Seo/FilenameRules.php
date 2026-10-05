<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * A descriptive file name for an image, from the words that describe it
 * (SEO layer §11): the alt text Ghostwriter wrote for it first, then the
 * library's own words. No model.
 *
 * Text\Slug::make() on the text, with what photo libraries add that says
 * nothing about the photo taken out ("stock photo", "royalty free",
 * "image of": the language's `filename_noise`) and the language's stop
 * words, at most six words and 50 characters. Fewer than two words left
 * (words of letters, not
 * numbers: "IMG 2231") is no name: the next source is tried.
 *
 *     FilenameRules::descriptive('A walled garden in winter, stock photo');   // "walled-garden-winter"
 */
final class FilenameRules
{
    public const MAX_WORDS = 6;

    public const MAX_LENGTH = 50;

    /** A name from one text; '' when it gives fewer than two words. */
    public static function descriptive(?string $text, string $language = 'en', int $max = self::MAX_LENGTH): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $plain = ' '.mb_strtolower(NormalisedText::string(SeoMetaCheck::confirmed($text))).' ';
        $noise = Phrases::for($language)->filenameNoise ?? Phrases::for('en')->filenameNoise ?? [];
        usort($noise, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($noise as $phrase) {
            $plain = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($phrase), '/').'(?![\p{L}\p{N}])/u', ' ', $plain);
        }

        $slug = Slug::make($plain, 300);

        if ($slug === '') {
            return '';
        }

        $words = SlugRules::withoutStopWords(explode('-', $slug), $language);
        $words = array_values(array_filter($words, fn (string $word) => $word !== ''));

        if (count(array_filter($words, fn (string $word) => preg_match('/[a-z]/', $word) === 1)) < 2) {
            return '';
        }

        $max = max(10, min($max, self::MAX_LENGTH));
        $words = array_slice($words, 0, self::MAX_WORDS);

        while (count($words) > 2 && strlen(implode('-', $words)) > $max) {
            array_pop($words);
        }

        return substr(implode('-', $words), 0, $max);
    }

    /**
     * The first of several texts that gives a name, in order.
     *
     * @param  array<int, string|null>  $texts
     */
    public static function first(array $texts, string $language = 'en', int $max = self::MAX_LENGTH): string
    {
        foreach ($texts as $text) {
            $name = self::descriptive($text, $language, $max);

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }
}
