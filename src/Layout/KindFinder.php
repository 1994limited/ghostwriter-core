<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Finds the kinds of entry a group already holds by grouping entries that
 * are built the same way. A Pages section might turn out to hold landing
 * pages, service pages and a few one-offs; nobody has to say so.
 *
 * It is what makes the options different on every site: they come from
 * that site's own content, with no configuration and no model call.
 */
final class KindFinder
{
    /** Two entries are the same kind when this share of their block types match. */
    private const SIMILARITY = 0.6;

    /** Example entries offered per kind. */
    private const EXAMPLES = 6;

    /** Entries an adapter should look at: enough to see the kinds, few enough to stay quick. */
    public const SAMPLE = 120;

    public function __construct(private readonly LayoutOptions $options = new LayoutOptions) {}

    /**
     * @param  array<int, EntryData>  $entries  The group's published entries, newest first. Only the page builder's value is read, with each entry's ID, title and parent.
     * @return array<int, FoundKind>
     */
    public function find(Schema $schema, array $entries): array
    {
        $field = $this->builder($schema);

        // Without a page builder every entry is built the same way.
        if ($field === null) {
            return [];
        }

        $entries = array_values($entries);
        $shapes = [];
        $members = [];

        foreach ($entries as $entry) {
            $blocks = $this->blocks($entry->values[$field->handle] ?? []);

            if ($blocks === []) {
                continue;
            }

            foreach ($shapes as $i => $shape) {
                if ($this->similarity($blocks, $shape) >= self::SIMILARITY) {
                    $members[$i][] = $entry;

                    continue 2;
                }
            }

            $shapes[] = $blocks;
            $members[] = [$entry];
        }

        $kinds = [];

        foreach ($members as $i => $group) {
            if (count($group) >= 2) {
                $kinds[] = ['blocks' => $shapes[$i], 'entries' => $group];
            }
        }

        usort($kinds, fn (array $a, array $b) => count($b['entries']) <=> count($a['entries']));

        // One group covering nearly everything is just "the group".
        if (count($kinds) < 2 && array_sum(array_map(fn (array $group) => count($group['entries']), $kinds)) >= count($entries) - 1) {
            return [];
        }

        return array_map(fn (array $group) => new FoundKind(
            $this->label($group['entries']),
            count($group['entries']),
            array_values(array_filter(array_map(fn (EntryData $entry) => $entry->id, array_slice($group['entries'], 0, self::EXAMPLES)), fn ($id) => $id !== null)),
            array_map(fn (EntryData $entry) => $entry->title(), $group['entries']),
            $group['blocks'],
        ), $kinds);
    }

    /**
     * The page builder entries are grouped by: the first `blocks` field, or
     * with kindsFromAnyBuilder the first builder of any kind.
     */
    private function builder(Schema $schema): ?Field
    {
        foreach ($schema->fields as $field) {
            if ($field->kind === Kind::Blocks || ($this->options->kindsFromAnyBuilder && $field->isBuilder())) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The types of the top-level blocks that are switched on.
     *
     * @return array<int, string>
     */
    private function blocks(mixed $value): array
    {
        $blocks = [];

        foreach ((array) $value as $set) {
            if (is_array($set) && isset($set['type']) && is_scalar($set['type']) && ($set['enabled'] ?? true) !== false) {
                $blocks[] = (string) $set['type'];
            }
        }

        return $blocks;
    }

    /**
     * Share of block types the two have in common.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function similarity(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);

        return count(array_intersect($a, $b)) / max(count(array_unique([...$a, ...$b])), 1);
    }

    /**
     * Named after the page the entries sit under when they share one,
     * otherwise after the entries themselves.
     *
     * @param  array<int, EntryData>  $entries
     */
    private function label(array $entries): string
    {
        $parents = array_unique(array_map(fn (EntryData $entry) => $entry->parentId === null ? null : (string) $entry->parentId, $entries), SORT_REGULAR);
        $parent = count($parents) === 1 && reset($parents) ? $entries[0] : null;

        if ($parent !== null) {
            return 'Like the pages under '.$parent->parentTitle;
        }

        $titles = array_map(fn (EntryData $entry) => $entry->title(), array_slice($entries, 0, 2));
        $more = count($entries) - 2;

        if ($this->options->kindLabel !== null) {
            return ($this->options->kindLabel)($titles, max($more, 0));
        }

        return 'Like '.($more > 0 ? implode(', ', $titles).' and '.$more.' more' : implode(' and ', $titles));
    }
}
