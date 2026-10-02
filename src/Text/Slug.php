<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

use Transliterator;

/**
 * Short, clean text for file names, titles and alt text, from whatever a
 * photo library or a model wrote.
 *
 *     Slug::make('Crème brûlée à Paris');         // "creme-brulee-a-paris"
 *     Slug::clip("  A   long\ncaption ...", 20);  // "A long caption", cut at a word
 */
final class Slug
{
    /** Latin letters that don't come apart into a base letter and an accent. */
    private const LETTERS = [
        'ß' => 'ss', 'ẞ' => 'SS', 'æ' => 'ae', 'Æ' => 'AE', 'œ' => 'oe', 'Œ' => 'OE',
        'ø' => 'o', 'Ø' => 'O', 'ł' => 'l', 'Ł' => 'L', 'đ' => 'd', 'Đ' => 'D',
        'ð' => 'd', 'Ð' => 'D', 'þ' => 'th', 'Þ' => 'TH', 'ı' => 'i', 'ħ' => 'h', 'Ħ' => 'H',
        '’' => '', "'" => '', '&' => ' and ',
    ];

    /**
     * Lower-case ASCII words joined by hyphens, at most $max characters,
     * cut between words where it can be. Accented letters lose their
     * accents; scripts with no Latin form are transliterated when the intl
     * extension is there, and dropped when it isn't. Empty when nothing is
     * left.
     */
    public static function make(string $text, int $max = 60): string
    {
        $text = strtr(self::clean($text), self::LETTERS);
        $text = self::ascii($text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        if (strlen($slug) <= $max) {
            return $slug;
        }

        $cut = substr($slug, 0, $max + 1);
        $last = strrpos($cut, '-');

        return trim($last !== false && $last >= $max / 2 ? substr($cut, 0, $last) : substr($slug, 0, $max), '-');
    }

    /**
     * Text on one line with no control characters or runs of spaces, at
     * most $max characters, cut at a word where it can be. Trailing commas,
     * colons and the like are taken off a cut.
     */
    public static function clip(string $text, int $max): string
    {
        $text = self::clean($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = $space !== false && $space >= $max / 2 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);

        return rtrim($cut, " \t,;:-–—/(");
    }

    /** The first letter in upper case, the rest left alone. */
    public static function upperFirst(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function clean(string $text): string
    {
        $text = Utf8::scrub($text);
        $text = (string) preg_replace('/[\p{C}\s]+/u', ' ', is_string($text) ? $text : '');

        return trim($text);
    }

    private static function ascii(string $text): string
    {
        if (class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            $result = $transliterator?->transliterate($text);

            if (is_string($result)) {
                return $result;
            }
        }

        // Without intl: take the accents off, which normalising to NFD would
        // do, by hand for the Latin letters people actually use.
        return strtr($text, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'ď' => 'd', 'Ď' => 'D',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
            'ğ' => 'g', 'Ğ' => 'G', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Į' => 'I', 'İ' => 'I',
            'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ő' => 'o',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ō' => 'O', 'Ő' => 'O',
            'ř' => 'r', 'Ř' => 'R', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's', 'Ś' => 'S', 'Š' => 'S', 'Ş' => 'S', 'Ș' => 'S',
            'ť' => 't', 'ţ' => 't', 'ț' => 't', 'Ť' => 'T', 'Ţ' => 'T', 'Ț' => 'T',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ū' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
            'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y', 'Ÿ' => 'Y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'Ź' => 'Z', 'Ż' => 'Z', 'Ž' => 'Z',
        ]);
    }
}
