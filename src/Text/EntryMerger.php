<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

/**
 * Lays a rewritten draft over the entry it was made from. The writer only
 * deals in words, so everything else an existing entry holds (images, links,
 * chosen entries, settings, the IDs of its blocks and rows) is carried over
 * from the entry and only the writing changes.
 *
 * Blocks are matched by type, in order. A group is merged field by field.
 *
 * Options, where the addons differ today:
 * - `$keepMissing` (default true): fields the draft does not hold at all
 *   (references, settings it left out) are copied from the entry, apart from
 *   the title. Pass false where the caller merges the result over the saved
 *   entry itself, so only the fields the draft holds are returned.
 * - `$mergeRows` (default false): rows are matched to the entry's rows by
 *   position, keeping each row's ID and the fields the draft doesn't hold.
 *   When false, the draft's rows replace the entry's as they stand.
 */
class EntryMerger
{
    public function __construct(
        private readonly bool $keepMissing = true,
        private readonly bool $mergeRows = false,
    ) {}

    /**
     * @param  array<string, mixed>  $built  Entry data built from the revised draft.
     * @param  array<string, mixed>  $original  The entry's data as saved.
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function merge(array $built, array $original, array $schema): array
    {
        $specs = array_column($schema, null, 'handle');

        foreach ($built as $handle => $value) {
            $spec = $specs[$handle] ?? null;
            $was = $original[$handle] ?? null;

            if (! $spec || ! is_array($value) || ! is_array($was)) {
                continue;
            }

            $built[$handle] = match ($spec['kind']) {
                'blocks' => $this->blocks($value, $was, $spec),
                'rows' => $this->mergeRows ? $this->rows($value, $was, $spec['fields'] ?? []) : $value,
                'group' => $this->merge($value, $was, $spec['fields'] ?? []) + $was,
                default => $value,
            };
        }

        if ($this->keepMissing) {
            // What the draft does not hold (references, settings it left out)
            // stays as the entry had it.
            foreach ($specs as $handle => $spec) {
                if (! array_key_exists($handle, $built) && array_key_exists($handle, $original) && $handle !== 'title') {
                    $built[$handle] = $original[$handle];
                }
            }
        }

        return $built;
    }

    /**
     * Blocks are matched by type, in order: the second text block of the
     * draft is the entry's second text block, wherever either now sits.
     *
     * @param  array<int|string, mixed>  $built
     * @param  array<int|string, mixed>  $original
     * @param  array<string, mixed>  $spec
     * @return array<int, mixed>
     */
    private function blocks(array $built, array $original, array $spec): array
    {
        $waiting = [];
        $hidden = [];

        foreach ($original as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            // The draft never saw blocks that are switched off; they are
            // kept as they were, after the rest.
            if (($block['enabled'] ?? true) === false) {
                $hidden[] = $block;
            } else {
                $waiting[$block['type']][] = $block;
            }
        }

        $merged = [];

        foreach ($built as $block) {
            if (! is_array($block) || ! isset($block['type'])) {
                continue;
            }

            $was = isset($waiting[$block['type']]) ? array_shift($waiting[$block['type']]) : null;

            if ($was === null) {
                $merged[] = $block;

                continue;
            }

            $fields = $spec['sets'][$block['type']]['fields'] ?? [];

            $merged[] = ['id' => $was['id'] ?? ($block['id'] ?? null)] + $this->merge($block, $was, $fields) + $was;
        }

        return [...$merged, ...$hidden];
    }

    /**
     * @param  array<int|string, mixed>  $built
     * @param  array<int|string, mixed>  $original
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int|string, mixed>
     */
    private function rows(array $built, array $original, array $fields): array
    {
        foreach ($built as $i => $row) {
            $was = $original[$i] ?? null;

            if (is_array($row) && is_array($was)) {
                $built[$i] = ['id' => $was['id'] ?? ($row['id'] ?? null)] + $this->merge($row, $was, $fields) + $was;
            }
        }

        return $built;
    }
}
