<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Marks the layout most like the site's own pages "Suggested". It means
 * *like your pages*, not *best*.
 *
 * - **Page builders:** for each site pattern of the field, one minus the
 *   normalised Levenshtein distance between the plan's block types and the
 *   pattern's, weighted by the pattern's share of entries.
 * - **Rich text:** one minus the distance between the arranged text's
 *   structure and the group's profile (headings per 100 words, over 5 so
 *   a gap of five counts fully; list and quote shares).
 *
 * The highest total wins. Ties go to the earlier plan, so the writer's.
 */
final class Candidates
{
    /**
     * @param  list<array{id: string, field: string, sequence: list<string>, count: int, share: float, example: string, exampleId: int|string|null}>  $patterns  SitePatterns::find().
     * @param  array<string, array{headings: float, lists: float, quotes: float, entries: int}>  $profile  SitePatterns::profile().
     * @param  Extras|array<int, mixed>  $extras
     * @param  Draft|array<string, mixed>  $draft
     */
    public function rank(Plans $plans, array $patterns, array $profile, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema): Plans
    {
        $best = null;
        $bestScore = -INF;
        $arranger = new Arranger;

        foreach ($plans as $plan) {
            $score = self::score($plan, $patterns);

            if ($profile !== []) {
                $data = $arranger->arrange($plan, $units, $extras, $draft, $schema);

                foreach ($profile as $handle => $site) {
                    if (is_string($data[$handle] ?? null)) {
                        $score += 1 - self::distance(SitePatterns::measure($data[$handle]), $site);
                    }
                }
            }

            if ($score > $bestScore + 1e-9) {
                [$best, $bestScore] = [$plan->id, $score];
            }
        }

        return new Plans(array_map(fn (Plan $plan) => $plan->with(suggested: $plan->id === $best), $plans->all()));
    }

    /**
     * How closely a plan's page builders follow the site's patterns.
     *
     * @param  list<array{id: string, field: string, sequence: list<string>, count: int, share: float, example: string, exampleId: int|string|null}>  $patterns
     */
    public static function score(Plan $plan, array $patterns): float
    {
        $score = 0.0;
        $sequences = $plan->sequences();

        foreach ($patterns as $pattern) {
            if (! isset($sequences[$pattern['field']])) {
                continue;
            }

            $score += $pattern['share'] * (1 - self::levenshtein($sequences[$pattern['field']], $pattern['sequence']));
        }

        return $score;
    }

    /**
     * Levenshtein distance over block types, over the longer length: 0 the same, 1 nothing alike.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function levenshtein(array $a, array $b): float
    {
        $longest = max(count($a), count($b));

        if ($longest === 0) {
            return 0.0;
        }

        $previous = range(0, count($b));

        foreach ($a as $i => $x) {
            $current = [$i + 1];

            foreach ($b as $j => $y) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($x === $y ? 0 : 1));
            }

            $previous = $current;
        }

        return $previous[count($b)] / $longest;
    }

    /**
     * @param  array{headings: float, lists: float, quotes: float}  $a
     * @param  array{headings: float, lists: float, quotes: float, entries?: int}  $b
     */
    private static function distance(array $a, array $b): float
    {
        return min(1.0, (min(5.0, abs($a['headings'] - $b['headings'])) / 5 + abs($a['lists'] - $b['lists']) + abs($a['quotes'] - $b['quotes'])) / 3);
    }
}
