<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Only layouts that look noticeably different are offered. Run after
 * PlanValidator, on the plans it kept: each alternative is compared
 * (LayoutDiff) with the writer's and with every alternative already kept,
 * and is kept only if it is noticeably different from all of them.
 *
 * Noticeably different is any of:
 *
 * - a page builder's blocks changed: one added, dropped or of another set
 *   (BLOCK_EDITS);
 * - a quarter of the page changed (SHARE), by weight (LayoutDiff);
 * - changes in three or more places (REGIONS) that add up to at least
 *   SPREAD_SHARE of the page.
 *
 * Tuned on real plans: a rich-text journal post whose alternatives set
 * one closing line as a quote (1% of the page, one place) or turned two
 * lists into paragraphs (12%, two places) is dropped; a pages builder that
 * splits its text into blocks around the quote, or adds a call to action
 * under the hero, is kept, as is a post whose ten lead-in paragraphs
 * become two checklists (61%).
 *
 * Near-copies of each other: the preferred one (the Suggested one, when
 * the caller says) is weighed first, so it is the one kept.
 */
final class LayoutGate
{
    public const BLOCK_EDITS = 1;

    public const SHARE = 0.25;

    public const REGIONS = 3;

    public const SPREAD_SHARE = 0.15;

    /**
     * @param  list<Plan>  $plans  The writer's first, then the alternatives, in order.
     * @param  Extras|array<int, mixed>  $extras
     * @return array{kept: list<Plan>, dropped: array<string, string>} The plans kept, in their order; why each dropped one went, by its id.
     */
    public function filter(array $plans, Units $units, Extras|array $extras, Schema $schema, ?string $prefer = null): array
    {
        $writer = null;

        foreach ($plans as $plan) {
            if ($plan->origin === PlanOrigin::Writer) {
                $writer = $plan;

                break;
            }
        }

        if ($writer === null) {
            return ['kept' => $plans, 'dropped' => []];
        }

        $extras = $extras instanceof Extras ? $extras : Extras::fromArray($extras);
        $order = array_values(array_filter($plans, fn (Plan $plan) => $plan !== $writer));
        usort($order, fn (Plan $a, Plan $b) => ($b->id === $prefer) <=> ($a->id === $prefer));

        $kept = [$writer];
        $dropped = [];

        foreach ($order as $plan) {
            foreach ($kept as $other) {
                $diff = LayoutDiff::between($other, $plan, $writer, $units, $extras, $schema);

                if (! self::noticeable($diff)) {
                    $dropped[$plan->id] = self::why($diff, $other);

                    continue 2;
                }
            }

            $kept[] = $plan;
        }

        return ['kept' => array_values(array_filter($plans, fn (Plan $plan) => in_array($plan, $kept, true))), 'dropped' => $dropped];
    }

    public static function noticeable(LayoutDiff $diff): bool
    {
        return $diff->blockEdits >= self::BLOCK_EDITS
            || $diff->share() >= self::SHARE
            || ($diff->regions >= self::REGIONS && $diff->share() >= self::SPREAD_SHARE);
    }

    /** Why a plan was too like another, for the log: numbers, no words. */
    private static function why(LayoutDiff $diff, Plan $other): string
    {
        $like = $other->origin === PlanOrigin::Writer ? 'the writer\'s' : $other->id;

        return sprintf('too like %s (%d%% of the page in %d %s, %d block %s)', $like, (int) round($diff->share() * 100), $diff->regions, $diff->regions === 1 ? 'place' : 'places', $diff->blockEdits, $diff->blockEdits === 1 ? 'change' : 'changes');
    }
}
