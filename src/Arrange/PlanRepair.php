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
    public function repair(Plan $plan, Units $units, Extras $extras, Plan $writer): Plan
    {
        $content = new Content($units, $extras);
        $fields = [];

        foreach ($plan->fields as $handle => $blocks) {
            $fields[$handle] = $this->prune($blocks, $content);
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
