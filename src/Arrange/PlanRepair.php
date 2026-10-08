<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;

/**
 * Brings a plan up to date with the draft's text after an edit, with no
 * model (§6.2.2). Unit ids are carried across edits (UnitMatcher), so most
 * of a plan still points at the right words.
 *
 * - **A removed unit** (or a piece or lead-in that is no longer there, or
 *   an extra item the editor deleted) is taken out of its placements. A
 *   placement left empty goes, and so does a block left with nothing.
 * - **A unit rewritten in place** (a heading reworded past what
 *   UnitMatcher carries, so it has a new id) takes the old one's place:
 *   when the writer's layout before the edit and after it have the same
 *   block (same type, sharing a unit that is still there) and one of its
 *   fields went from only removed units to only new ones, the new ones
 *   go wherever the old ones were. A reworded hero heading stays in the
 *   hero rather than opening a hero of its own.
 * - **A new unit** goes after the unit before it in the draft: into the
 *   same placement when that field holds the same kind of value as the
 *   unit's own, otherwise in a new block of the writer's type for it,
 *   right after. With no unit before it, it opens its field.
 *
 * The result is checked by PlanValidator; a plan that still fails is
 * marked stale ("Needs refreshing") rather than re-planned.
 */
final class PlanRepair
{
    /**
     * @param  Plan|null  $was  The writer's layout before the edit, to find the units rewritten in place.
     */
    public function repair(Plan $plan, Units $units, Extras $extras, Plan $writer, ?Plan $was = null): Plan
    {
        $content = new Content($units, $extras);
        $rewritten = $was === null ? [] : $this->rewritten($was, $writer, $units);
        $fields = [];

        foreach ($plan->fields as $handle => $blocks) {
            $fields[$handle] = $this->prune($rewritten === [] ? $blocks : $this->substitute($blocks, $rewritten), $content);
        }

        $placed = [];

        foreach ((new Plan('', PlanOrigin::Model, '', '', $fields))->refs() as $ref) {
            $parsed = Content::parse($ref);

            if ($parsed !== null && $parsed['unit'] !== null) {
                $placed[$parsed['unit']] = true;
            }
        }

        $previous = null;

        foreach ($units->all() as $unit) {
            $handle = $unit->path->handle();

            if (isset($placed[$unit->id]) || $unit->kind === UnitKind::Media || ! isset($fields[$handle])) {
                $previous = isset($placed[$unit->id]) ? $unit : $previous;

                continue;
            }

            $fields[$handle] = $this->insert($fields[$handle], $unit, $previous !== null && $previous->path->handle() === $handle ? $previous : null, $writer->fields[$handle] ?? []);
            $placed[$unit->id] = true;
            $previous = $unit;
        }

        return $plan->with(fields: $fields);
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @return list<PlanBlock>
     */
    private function prune(array $blocks, Content $content): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $placements = [];

            foreach ($block->placements as $placement) {
                $from = array_values(array_filter($placement->from, fn (string $ref) => $content->pieces($ref) !== null));
                $rows = array_values(array_filter(array_map(fn (array $row) => array_filter($row, fn (string $ref) => $content->pieces($ref) !== null), $placement->rows())));
                $options = $placement->rows() === [] ? $placement->options : ['rows' => $rows] + $placement->options;

                if ($from !== [] || $rows !== []) {
                    $placements[] = new Placement($placement->field, $from, $placement->transform, $options);
                }
            }

            $children = array_map(fn (array $nested) => $this->prune($nested, $content), $block->children);
            $children = array_filter($children, fn (array $nested) => $nested !== []);
            $hadWords = $block->placements !== [] || $block->children !== [];

            if ($hadWords && $placements === [] && $children === []) {
                continue;
            }

            $out[] = new PlanBlock($block->type, $placements, $children, $block->settings, $block->origin);
        }

        return $out;
    }

    /**
     * The units rewritten in place: each removed unit's id, with the new
     * units that took its place in the writer's layout.
     *
     * @return array<string, list<string>>
     */
    private function rewritten(Plan $was, Plan $writer, Units $units): array
    {
        $now = array_fill_keys($units->ids(), true);
        $before = array_fill_keys(self::unitsOf($was->refs()), true);
        $map = [];

        foreach ($writer->fields as $handle => $blocks) {
            $old = self::flatten($was->fields[$handle] ?? []);

            foreach (self::flatten($blocks) as $block) {
                $kept = array_filter(self::unitsOf($block->refs()), fn (string $id) => isset($before[$id]) && isset($now[$id]));
                $match = null;

                foreach ($old as $candidate) {
                    if ($candidate->type === $block->type && array_intersect($kept, self::unitsOf($candidate->refs())) !== []) {
                        $match = $candidate;

                        break;
                    }
                }

                if ($match === null) {
                    continue;
                }

                foreach ($block->placements as $placement) {
                    $previous = $match->placement($placement->field);

                    // Whole units only: pieces and extras are left to the rules below.
                    if ($previous === null || ! self::whole($previous->from) || ! self::whole($placement->from)) {
                        continue;
                    }

                    $gone = array_filter($previous->from, fn (string $ref) => ! isset($now[$ref]));
                    $new = array_filter($placement->from, fn (string $ref) => isset($now[$ref]) && ! isset($before[$ref]));

                    if (count($gone) === count($previous->from) && count($new) === count($placement->from)) {
                        $map[$previous->from[0]] = array_values($placement->from);
                    }
                }
            }
        }

        return $map;
    }

    /**
     * A plan's blocks with each rewritten unit's ref swapped for the units
     * that took its place.
     *
     * @param  list<PlanBlock>  $blocks
     * @param  array<string, list<string>>  $rewritten
     * @return list<PlanBlock>
     */
    private function substitute(array $blocks, array $rewritten): array
    {
        return array_map(fn (PlanBlock $block) => $block->with(
            array_map(function (Placement $placement) use ($rewritten): Placement {
                $from = [];

                foreach ($placement->from as $ref) {
                    array_push($from, ...($rewritten[$ref] ?? [$ref]));
                }

                return $placement->with(array_values(array_unique($from)));
            }, $block->placements),
            array_map(fn (array $nested) => $this->substitute($nested, $rewritten), $block->children),
        ), $blocks);
    }

    /**
     * @param  list<string>  $refs
     * @return list<string>
     */
    private static function unitsOf(array $refs): array
    {
        return array_values(array_filter(array_map(fn (string $ref) => Content::parse($ref)['unit'] ?? null, $refs)));
    }

    /**
     * Whether every ref is a whole unit (not a piece of one, or an extra), and there is one.
     *
     * @param  list<string>  $refs
     */
    private static function whole(array $refs): bool
    {
        return $refs !== [] && self::unitsOf($refs) === $refs;
    }

    /**
     * A field's blocks and every block nested in them.
     *
     * @param  list<PlanBlock>  $blocks
     * @return list<PlanBlock>
     */
    private static function flatten(array $blocks): array
    {
        $all = [];

        foreach ($blocks as $block) {
            $all[] = $block;

            foreach ($block->children as $nested) {
                array_push($all, ...self::flatten($nested));
            }
        }

        return $all;
    }

    /**
     * One new unit into a field's blocks.
     *
     * @param  list<PlanBlock>  $blocks
     * @param  list<PlanBlock>  $writerBlocks
     * @return list<PlanBlock>
     */
    private function insert(array $blocks, Unit $unit, ?Unit $after, array $writerBlocks): array
    {
        $own = $this->writerBlock($writerBlocks, $unit->id);
        $new = $own === null ? null : new PlanBlock($own->type, array_values(array_filter(array_map(
            fn (Placement $placement) => in_array($unit->id, $placement->from, true) ? new Placement($placement->field, [$unit->id]) : null,
            $own->placements,
        ))));

        if ($after === null) {
            return $new === null ? $blocks : [$new, ...$blocks];
        }

        // Where the unit before it ends: its last ref in the field.
        $last = null;

        foreach ($blocks as $i => $block) {
            foreach ($block->placements as $j => $placement) {
                foreach ($placement->from as $k => $ref) {
                    if ((Content::parse($ref)['unit'] ?? null) === $after->id) {
                        $last = [$i, $j, $k, $ref === $after->id];
                    }
                }
            }
        }

        if ($last === null) {
            return $new === null ? $blocks : [...$blocks, $new];
        }

        [$i, $j, $k, $whole] = $last;
        $placement = $blocks[$i]->placements[$j];

        // A whole unit of the same kind of value before it: it joins the placement.
        if ($whole && $this->sameKind($placement, $unit, $after)) {
            $from = $placement->from;
            array_splice($from, $k + 1, 0, [$unit->id]);
            $placements = $blocks[$i]->placements;
            $placements[$j] = $placement->with(array_values($from));
            $blocks[$i] = $blocks[$i]->with(array_values($placements));

            return array_values($blocks);
        }

        if ($new !== null) {
            array_splice($blocks, $i + 1, 0, [$new]);
        }

        return array_values($blocks);
    }

    /** Whether a placement holding $after can take $unit too: both from one value of one kind. */
    private function sameKind(Placement $placement, Unit $unit, Unit $after): bool
    {
        return $placement->transform === Transform::AsIs
            && $after->path->dotted() === $unit->path->dotted()
            && in_array($unit->kind, [UnitKind::Prose, UnitKind::Section, UnitKind::List, UnitKind::Quote], true)
            && in_array($after->kind, [UnitKind::Prose, UnitKind::Section, UnitKind::List, UnitKind::Quote], true);
    }

    /**
     * The writer's block (at any depth) that places a unit.
     *
     * @param  list<PlanBlock>  $blocks
     */
    private function writerBlock(array $blocks, string $id): ?PlanBlock
    {
        foreach ($blocks as $block) {
            foreach ($block->placements as $placement) {
                if (in_array($id, $placement->from, true)) {
                    return $block;
                }
            }

            foreach ($block->children as $children) {
                $found = $this->writerBlock($children, $id);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
