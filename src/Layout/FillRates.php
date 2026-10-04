<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * How often a group's entries fill each field: on the entry, keyed by
 * handle, and on blocks, keyed "blockType.handle", at any depth. It is how
 * an image that every page has is told from a background few pages use.
 *
 * PatternFinder keeps these in a Pattern's `filled`. Finish this page needs
 * only them, so an addon can count them alone over its newest published
 * entries (SIBLINGS of them), keep the result per group, and count again
 * when an entry in the group is saved: no model, and no scan of a big
 * group on every check.
 *
 *     $pattern = FillRates::pattern($schema, $newestPublished); // Pattern(entries: 20, filled: [...])
 */
final class FillRates
{
    /** How many of a group's newest published entries Finish this page compares a page with. */
    public const SIBLINGS = 20;

    /**
     * A pattern holding only the fill rates of these entries, and how many
     * there were.
     *
     * @param  array<int, EntryData>  $entries  The newest first; only the first SIBLINGS are read.
     */
    public static function pattern(Schema $schema, array $entries): Pattern
    {
        $entries = array_slice(array_values($entries), 0, self::SIBLINGS);

        return new Pattern(
            entries: count($entries),
            filled: self::of(array_map(fn (EntryData $entry) => $entry->values, $entries), $schema->fields),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  Entries' (or blocks') values.
     * @param  array<int, Field>  $fields
     * @return array<string, float>
     */
    public static function of(array $items, array $fields): array
    {
        $counts = [];
        $totals = [];

        $walk = function (array $items, array $fields, string $prefix) use (&$walk, &$counts, &$totals): void {
            foreach ($items as $item) {
                if (! is_array($item) || ($item['enabled'] ?? true) === false) {
                    continue;
                }

                foreach ($fields as $field) {
                    $key = $prefix.$field->handle;
                    $value = $item[$field->handle] ?? null;

                    $totals[$key] = ($totals[$key] ?? 0) + 1;

                    if ($value !== null && $value !== '' && $value !== []) {
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }

                    if ($field->isBuilder() && is_array($value)) {
                        foreach ($value as $block) {
                            $type = is_array($block) && is_scalar($block['type'] ?? null) ? (string) $block['type'] : '';
                            $set = $field->set($type);

                            if ($set !== null && is_array($block)) {
                                $walk([$block], $set->fields, $type.'.');
                            }
                        }
                    }
                }
            }
        };

        $walk($items, $fields, '');

        $rates = [];

        foreach ($totals as $key => $total) {
            $rates[$key] = round(($counts[$key] ?? 0) / $total, 2);
        }

        return $rates;
    }
}
