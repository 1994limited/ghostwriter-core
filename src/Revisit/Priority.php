<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;

/**
 * How much an entry needs a look, from 0 to 100, from its reasons alone:
 * each kind's weight times its count, up to the kind's cap, then age. In
 * a dated group (AgePolicy) age, past years and relative time weigh a
 * quarter.
 *
 * The SEO reasons (ReasonKind::isSeo()) weigh less than broken links,
 * closing dates and leftovers, as a page that's wrong matters more than a
 * page that's hard to find, and all of them together count for at most
 * SEO_CAP, so no page is "High" on SEO alone (SEO layer §13.3).
 */
final class Priority
{
    public const WEIGHTS = [
        'leftover' => 40, 'closing-date' => 30, 'broken-link' => 25, 'external-link' => 15, 'past-year' => 20, 'relative-time' => 15,
        'empty-field' => 10, 'stated-count' => 8, 'missing-alt' => 6, 'seo-length' => 5, 'age' => 20,
        'competing' => 10, 'seo-missing' => 6, 'few-links' => 5, 'heading-levels' => 3, 'readability' => 2,
    ];

    public const CAPS = ['broken-link' => 40, 'external-link' => 30, 'past-year' => 35, 'relative-time' => 25, 'missing-alt' => 18, 'empty-field' => 20, 'stated-count' => 16, 'leftover' => 60, 'closing-date' => 40,
        'competing' => 20, 'seo-missing' => 6, 'few-links' => 5, 'heading-levels' => 3, 'readability' => 4];

    /** All the SEO reasons together count for at most this. */
    public const SEO_CAP = 25;

    /** Rows at or above this are "worth a look" (the first tile). */
    public const WORTH_A_LOOK = 30;

    /**
     * @param  array<int, RevisitReason>  $reasons
     */
    public function score(array $reasons, ?DateTimeImmutable $updatedAt, DateTimeImmutable $now, AgePolicy $age, string $group): int
    {
        $score = 0.0;
        $seo = 0.0;
        $weight = $age->weight($group);

        foreach ($reasons as $reason) {
            if ($reason->kind === ReasonKind::Age) {
                continue;
            }

            $points = min(self::CAPS[$reason->kind->value] ?? PHP_INT_MAX, self::WEIGHTS[$reason->kind->value] * max(1, $reason->count));

            if ($reason->kind->isSeo()) {
                $seo += $points;

                continue;
            }

            $score += $reason->kind->isTimely() ? $points * $weight : $points;
        }

        $score += min(self::SEO_CAP, $seo);

        $score += self::WEIGHTS['age'] * $age->share($updatedAt, $now) * $weight;

        return (int) min(100, round($score));
    }

    /** The word beside the bar: 'high', 'medium' or 'low' (`revisit.priority.*`). */
    public static function word(int $score): string
    {
        return match (true) {
            $score >= 60 => 'high',
            $score >= self::WORTH_A_LOOK => 'medium',
            default => 'low',
        };
    }
}
