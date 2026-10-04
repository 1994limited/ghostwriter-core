<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * Dates written in text, with a year: ISO ("2025-01-31"), numeric
 * day-first ("31/01/2025", "31.01.2025"), and with a month name in the
 * page's language ("31 January 2025", "January 31, 2025", "31. Januar
 * 2025", "31 de enero de 2025", "1er février 2025"). A date without a year
 * is never read: which year it means can't be told.
 *
 * Numeric dates are read day first, as everywhere but the United States.
 */
final class Dates
{
    /**
     * Every date in some text, in order: what was written, where (in
     * bytes) and the day it names.
     *
     * @return list<array{text: string, offset: int, date: DateTimeImmutable}>
     */
    public static function find(string $text, ?Phrases $phrases = null): array
    {
        $patterns = [
            '/(?<!\d)(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})(?!\d)/u',
            '/(?<![\d\/.])(?<d>\d{1,2})[\/.](?<m>\d{1,2})[\/.](?<y>\d{4})(?!\d)/u',
        ];

        if ($phrases !== null && $phrases->months !== []) {
            $months = $phrases->monthNames();
            $patterns[] = '/(?<![\p{L}\d])(?<d>\d{1,2})(?:st|nd|rd|th|er|e|\.|º)?\s+(?:de\s+)?(?<mn>'.$months.')\.?,?\s+(?:de\s+)?(?<y>\d{4})(?!\d)/iu';
            $patterns[] = '/(?<![\p{L}])(?<mn>'.$months.')\.?\s+(?<d>\d{1,2})(?:st|nd|rd|th)?,?\s+(?<y>\d{4})(?!\d)/iu';
        }

        $found = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $month = isset($match['mn']) && $match['mn'][0] !== '' && $phrases !== null
                    ? ($phrases->months[mb_strtolower($match['mn'][0])] ?? 0)
                    : (int) ($match['m'][0] ?? 0);
                $day = (int) $match['d'][0];
                $year = (int) $match['y'][0];

                if (! checkdate($month, $day, $year)) {
                    continue;
                }

                $offset = $match[0][1];

                foreach ($found as $earlier) {
                    if ($offset < $earlier['offset'] + strlen($earlier['text']) && $earlier['offset'] < $offset + strlen($match[0][0])) {
                        continue 2;
                    }
                }

                $found[] = ['text' => $match[0][0], 'offset' => $offset, 'date' => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day))];
            }
        }

        usort($found, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $found;
    }

    /**
     * A stored date value (a date field's): "2025-01-31", "2025-01-31
     * 17:00", an ISO date-time, or `['date' => …]` / `['end' => …]` as
     * some CMSs keep ranges (the end is taken). Null when it isn't one.
     */
    public static function value(mixed $value): ?DateTimeImmutable
    {
        if (is_array($value)) {
            $value = $value['end'] ?? $value['date'] ?? $value['value'] ?? null;
        }

        if (! is_string($value) || preg_match('/^\s*\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?\s*$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10));

        return $date === false ? null : $date;
    }
}
