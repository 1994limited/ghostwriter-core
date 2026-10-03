<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;

/**
 * Carries unit ids from one draft to the next, so what points at a unit
 * (a comment) still points at its words after the writer's next turn, a
 * hand edit or a revision.
 *
 * A new unit keeps an old unit's id when:
 * 1. it is at the same place (path and part), of the same kind, and its
 *    text is at least 60% similar; or else
 * 2. it is the best match anywhere for an old unit not yet carried, at
 *    least 50% similar.
 *
 * Similarity is the Jaccard index of the two texts' word pairs (single
 * words when either has fewer than four). Media units match only media
 * units, by their assets. Every other unit gets a new id; ids are never
 * reused.
 */
final class UnitMatcher
{
    public const SAME_PLACE = 0.6;

    public const ANYWHERE = 0.5;

    public function carry(Units $before, Units $after): Units
    {
        $old = $before->all();
        $new = $after->all();
        $ids = [];
        $used = [];

        foreach ($new as $i => $unit) {
            foreach ($old as $j => $was) {
                if (! isset($used[$j]) && $was->where() === $unit->where() && $was->kind === $unit->kind && self::similarity($was, $unit) >= self::SAME_PLACE) {
                    $ids[$i] = $was->id;
                    $used[$j] = true;

                    break;
                }
            }
        }

        $pairs = [];

        foreach ($new as $i => $unit) {
            if (isset($ids[$i])) {
                continue;
            }

            foreach ($old as $j => $was) {
                if (isset($used[$j])) {
                    continue;
                }

                $score = self::similarity($was, $unit);

                if ($score >= self::ANYWHERE) {
                    $pairs[] = [$score, $i, $j];
                }
            }
        }

        usort($pairs, fn (array $a, array $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        foreach ($pairs as [, $i, $j]) {
            if (! isset($ids[$i]) && ! isset($used[$j])) {
                $ids[$i] = $old[$j]->id;
                $used[$j] = true;
            }
        }

        $next = $before->next;
        $units = [];

        foreach ($new as $i => $unit) {
            $units[] = $unit->withId($ids[$i] ?? 'u'.$next++);
        }

        return Units::of($units, $next);
    }

    /** 0–1: how alike two units' words are. */
    public static function similarity(Unit $a, Unit $b): float
    {
        if (($a->kind === UnitKind::Media) !== ($b->kind === UnitKind::Media)) {
            return 0.0;
        }

        if ($a->kind === UnitKind::Media) {
            return $a->assets === $b->assets ? 1.0 : 0.0;
        }

        $x = NormalisedText::words($a->markdown);
        $y = NormalisedText::words($b->markdown);

        if ($x === [] || $y === []) {
            return $x === $y ? 1.0 : 0.0;
        }

        $size = min(count($x), count($y)) < 4 ? 1 : 2;
        $x = self::shingles($x, $size);
        $y = self::shingles($y, $size);

        return count(array_intersect_key($x, $y)) / count($x + $y);
    }

    /**
     * @param  list<string>  $words
     * @return array<string, true>
     */
    private static function shingles(array $words, int $size): array
    {
        $shingles = [];

        for ($i = 0; $i + $size <= count($words); $i++) {
            $shingles[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $shingles;
    }
}
