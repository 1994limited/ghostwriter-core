<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * The shapes a group's pages already have, for the layout planner to
 * follow and for "Suggested". PatternFinder keeps only the commonest order
 * of blocks; this keeps every distinct one (with PatternFinder's own
 * sequence code), and for rich text a profile of how the pages are
 * structured.
 */
final class SitePatterns
{
    public function __construct(private readonly RichTextDialect $richText = new HtmlDialect) {}

    /**
     * Each page builder's distinct block orders, commonest first (ties to
     * the newest), with how many entries use each and the newest of them
     * as an example. Ids are "p-1", "p-2"… across the fields.
     *
     * @param  array<int, EntryData>  $entries  Newest first, as PatternFinder takes them.
     * @return list<array{id: string, field: string, sequence: list<string>, count: int, share: float, example: string, exampleId: int|string|null}>
     */
    public function find(Schema $schema, array $entries, int $limit = 3): array
    {
        $patterns = [];

        foreach ($schema->fields as $field) {
            if (! $field->isBuilder()) {
                continue;
            }

            $using = array_values(array_filter($entries, fn (EntryData $entry) => ! empty($entry->values[$field->handle])));
            $sequences = array_map(fn (EntryData $entry) => PatternFinder::sequenceOf($entry->values[$field->handle]), $using);
            $counts = PatternFinder::sequences($sequences);
            $order = array_keys($counts);
            uksort($counts, fn ($a, $b) => [$counts[$b], array_search($a, $order, true)] <=> [$counts[$a], array_search($b, $order, true)]);

            foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
                $key = (string) $key;

                if ($key === '') {
                    continue;
                }

                $example = null;

                foreach ($using as $i => $entry) {
                    if (implode('>', $sequences[$i]) === $key) {
                        $example = $entry;

                        break;
                    }
                }

                $patterns[] = [
                    'id' => 'p-'.(count($patterns) + 1),
                    'field' => $field->handle,
                    'sequence' => explode('>', $key),
                    'count' => $count,
                    'share' => round($count / max(1, count($using)), 2),
                    'example' => $example?->title() ?? '',
                    'exampleId' => $example?->id,
                ];
            }
        }

        return $patterns;
    }

    /**
     * For each rich-text field, how the group's entries are structured, on
     * average: headings per 100 words, and the share of their blocks that
     * are list items and block quotes.
     *
     * @param  array<int, EntryData>  $entries
     * @return array<string, array{headings: float, lists: float, quotes: float, entries: int}>
     */
    public function profile(Schema $schema, array $entries): array
    {
        $profiles = [];

        foreach ($schema->fields as $field) {
            if (! Plans::isMarkdown($field)) {
                continue;
            }

            $rows = [];

            foreach ($entries as $entry) {
                $value = $entry->values[$field->handle] ?? null;
                $markdown = is_string($value) && $field->kind !== Kind::RichText ? $value : ($value === null || $value === '' ? null : $this->richText->toMarkdown($value, $field));

                if ($markdown !== null && trim($markdown) !== '') {
                    $rows[] = self::measure($markdown);
                }
            }

            if ($rows !== []) {
                $profiles[$field->handle] = [
                    'headings' => round(array_sum(array_column($rows, 'headings')) / count($rows), 2),
                    'lists' => round(array_sum(array_column($rows, 'lists')) / count($rows), 2),
                    'quotes' => round(array_sum(array_column($rows, 'quotes')) / count($rows), 2),
                    'entries' => count($rows),
                ];
            }
        }

        return $profiles;
    }

    /**
     * One value's structure.
     *
     * @return array{headings: float, lists: float, quotes: float}
     */
    public static function measure(string $markdown): array
    {
        $pieces = [];

        foreach (MarkdownSections::split($markdown) as $section) {
            array_push($pieces, ...$section['pieces']);
        }

        $words = max(1, count(preg_split('/\s+/u', trim($markdown), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $count = max(1, count($pieces));

        return [
            'headings' => round(count(array_filter($pieces, fn (Piece $piece) => $piece->kind === Piece::HEADING)) * 100 / $words, 2),
            'lists' => round(count(array_filter($pieces, fn (Piece $piece) => $piece->kind === Piece::ITEM)) / $count, 2),
            'quotes' => round(count(array_filter($pieces, fn (Piece $piece) => $piece->kind === Piece::QUOTE)) / $count, 2),
        ];
    }
}
