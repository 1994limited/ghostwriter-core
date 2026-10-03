<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * Figures compared by what they say, not how they are written: "800",
 * "eight hundred", "about 800" and "800-word" are the same figure, as are
 * "£12,000", "£12k" and "twelve thousand pounds", "40%" and "forty per
 * cent", and "14 May 2026" and "2026-05-14". Shared by BriefCheck and
 * Studio::fillGap(), which both refuse figures their source didn't have.
 *
 * Each figure is a value and a kind: money, a percentage, or plain. A
 * figure with a kind is given when the source has the same value with the
 * same kind, or as a plain number; a plain figure, when the source has the
 * value at all. A range is given when both ends are.
 *
 * @internal Shared by BriefCheck and Studio; not part of core's public API.
 */
final class Figures
{
    public const MONEY = 'money';

    public const PERCENT = '%';

    /** One figure in digits: a currency before it, an amount or a unit after it. */
    private const ONE = '(?:[£$€¥]\s?)?(?<![\w.,])\d+(?:,\d{3})*(?:\.\d+)?(?:(?:k|m|bn)\b|\s?(?:million|billion|thousand)\b)?(?:\s?(?:%|per\s?cent\b|percent\b|pounds?\b|dollars?\b|euros?\b|quid\b|gbp\b|usd\b|eur\b))?';

    /** A figure, or a range of two ("5–10k", "1,500 to 2,000"). */
    public const PATTERN = '/'.self::ONE.'(?:\s*(?:[-–—]|\bto\b)\s*'.self::ONE.')?/iu';

    private const NUMBER_WORDS = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16,
        'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8,
        'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12, 'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15,
        'sixteenth' => 16, 'seventeenth' => 17, 'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20, 'thirtieth' => 30,
    ];

    private const SCALES = ['hundred' => 100, 'thousand' => 1000, 'million' => 1000000, 'billion' => 1000000000];

    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * The figures written in digits in some text, with their offsets (in
     * bytes) and what each says.
     *
     * @return list<array{figure: string, offset: int, values: list<array{0: string, 1: string}>}>
     */
    public static function find(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return [];
        }

        return array_map(fn (array $match) => [
            'figure' => $match[0][0],
            'offset' => $match[0][1],
            'values' => self::values($match[0][0]),
        ], $matches);
    }

    /**
     * Everything some text says in figures, in digits or in words, with
     * month names as their numbers, for given().
     *
     * @return array{values: array<string, true>, kinds: array<string, true>}
     */
    public static function known(string $text): array
    {
        $known = ['values' => [], 'kinds' => []];
        $add = function (string $value, string $kind) use (&$known): void {
            $known['values'][$value] = true;
            $known['kinds'][$value.'|'.$kind] = true;
        };

        foreach (self::find($text) as $figure) {
            foreach ([...$figure['values'], ...self::values($figure['figure'], spread: false)] as [$value, $kind]) {
                $add($value, $kind);
            }
        }

        $words = implode('|', [...array_keys(self::NUMBER_WORDS), ...array_keys(self::SCALES), 'dozen']);
        $pattern = '/\b(?:an?\s+)?(?:'.$words.')(?:(?:\s+|-|\s+and\s+)(?:'.$words.'))*\b(?:\s?(%|per\s?cent\b|percent\b|pounds?\b|dollars?\b|euros?\b|quid\b))?/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $number = self::fromWords($match[0]);

                if ($number !== null) {
                    $add(self::format($number), self::kindOf($match[0]));
                }
            }
        }

        // A month next to a day or a year ("14 May", "May 14th", "May 2026"), as its number.
        $months = implode('|', array_keys(self::MONTHS));

        if (preg_match_all('/\b\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?('.$months.')\b|\b('.$months.')\.?\s+\d{1,4}\b/iu', $text, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                // One of the two groups matched; the other is empty or missing.
                $month = mb_strtolower(implode('', array_slice($match, 1)));

                if (isset(self::MONTHS[$month])) {
                    $add((string) self::MONTHS[$month], '');
                }
            }
        }

        return $known;
    }

    /**
     * Whether every value of a figure is one the source has.
     *
     * @param  list<array{0: string, 1: string}>  $values
     * @param  array{values: array<string, true>, kinds: array<string, true>}  $known
     */
    public static function given(array $values, array $known): bool
    {
        foreach ($values as [$value, $kind]) {
            $ok = $kind === ''
                ? isset($known['values'][$value])
                : isset($known['kinds'][$value.'|'.$kind]) || isset($known['kinds'][$value.'|']);

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * What one matched figure says: a value and a kind per end. With
     * `$spread`, a range shares its amount and kind ("£5–10k" is £5,000 to
     * £10,000; "20–30%" is two percentages).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function values(string $figure, bool $spread = true): array
    {
        $ends = preg_match_all('/'.self::ONE.'/iu', $figure, $found) > 0 ? $found[0] : [];
        $parts = array_map(fn (string $end) => [
            'number' => (float) str_replace(',', '', (string) (preg_match('/\d+(?:,\d{3})*(?:\.\d+)?/', $end, $m) === 1 ? $m[0] : '0')),
            'scale' => preg_match('/\d(k|m|bn)\b|\d\s?(million|billion|thousand)\b/iu', $end, $s) === 1 ? self::scale($s[1] !== '' ? $s[1] : ($s[2] ?? '')) : null,
            'kind' => self::kindOf($end),
        ], $ends);

        if ($spread && count($parts) === 2) {
            $parts[0]['scale'] ??= $parts[1]['scale'];
            $parts[1]['scale'] ??= $parts[0]['scale'];

            if ($parts[0]['kind'] === '') {
                $parts[0]['kind'] = $parts[1]['kind'];
            }

            if ($parts[1]['kind'] === '') {
                $parts[1]['kind'] = $parts[0]['kind'];
            }
        }

        return array_map(fn (array $part) => [self::format($part['number'] * ($part['scale'] ?? 1)), $part['kind']], $parts);
    }

    private static function scale(string $word): int
    {
        return match (mb_strtolower($word)) {
            'k', 'thousand' => 1000,
            'm', 'million' => 1000000,
            default => 1000000000,
        };
    }

    private static function kindOf(string $figure): string
    {
        if (preg_match('/%|per\s?cent|percent/iu', $figure) === 1) {
            return self::PERCENT;
        }

        return preg_match('/[£$€¥]|\b(?:pounds?|dollars?|euros?|quid|gbp|usd|eur)\b/iu', $figure) === 1 ? self::MONEY : '';
    }

    /**
     * A number written in words: "eight hundred", "twelve thousand",
     * "forty-two", "a dozen", "twenty-first".
     */
    private static function fromWords(string $words): ?float
    {
        $total = 0;
        $current = 0;
        $seen = false;

        foreach (preg_split('/[\s-]+/u', mb_strtolower($words)) ?: [] as $word) {
            if (isset(self::NUMBER_WORDS[$word])) {
                $current += self::NUMBER_WORDS[$word];
                $seen = true;
            } elseif ($word === 'dozen') {
                $current = max($current, 1) * 12;
                $seen = true;
            } elseif ($word === 'hundred') {
                $current = max($current, 1) * 100;
                $seen = true;
            } elseif (isset(self::SCALES[$word])) {
                $total += max($current, 1) * self::SCALES[$word];
                $current = 0;
                $seen = true;
            }
        }

        return $seen ? (float) ($total + $current) : null;
    }

    private static function format(float $number): string
    {
        $rounded = round($number, 6);

        return floor($rounded) === $rounded ? sprintf('%.0f', $rounded) : rtrim(rtrim(sprintf('%.6F', $rounded), '0'), '.');
    }
}
