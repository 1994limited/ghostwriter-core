<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * Splits one rich-text value, as markdown, into its units and their
 * pieces. The headings of the highest level the value uses start its
 * sections; anything before the first of them is one prose unit. A value
 * with no headings is one unit: a list, a quote, or prose.
 *
 * Only ATX headings (`## …`) outside code fences count, which is what the
 * dialects write.
 *
 * @internal Used by Units and Preview\PreviewMarkers, so both split a value the same way.
 */
final class MarkdownSections
{
    /**
     * @return list<array{kind: UnitKind, markdown: string, pieces: list<Piece>, line: int}> `line` is the section's first line (0-based) in the value.
     */
    public static function split(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $blocks = self::blocks($lines);

        if ($blocks === []) {
            return [];
        }

        $levels = array_map(fn (array $block) => $block['level'], array_filter($blocks, fn (array $block) => $block['kind'] === Piece::HEADING));
        $top = $levels === [] ? null : min($levels);
        $groups = [];

        foreach ($blocks as $block) {
            if ($groups === [] || ($top !== null && $block['kind'] === Piece::HEADING && $block['level'] === $top)) {
                $groups[] = [];
            }

            $groups[count($groups) - 1][] = $block;
        }

        $units = [];

        foreach ($groups as $group) {
            $first = $group[0];
            $last = $group[count($group) - 1];
            $text = trim(implode("\n", array_slice($lines, $first['start'], $last['end'] - $first['start'])));
            $pieces = array_values(array_map(fn (array $block) => new Piece($block['kind'], $block['markdown'], $block['level']), $group));
            $kinds = array_unique(array_map(fn (Piece $piece) => $piece->kind, $pieces));

            $kind = match (true) {
                $first['kind'] === Piece::HEADING && $first['level'] === $top => UnitKind::Section,
                $kinds === [Piece::ITEM] => UnitKind::List,
                $kinds === [Piece::QUOTE] => UnitKind::Quote,
                default => UnitKind::Prose,
            };

            $units[] = ['kind' => $kind, 'markdown' => $text, 'pieces' => $pieces, 'line' => $first['start']];
        }

        return $units;
    }

    /**
     * The value's blocks, each with its lines [start, end). Also used by
     * Seo\HeadingFixer, so it sees the same headings Units cuts at.
     *
     * @param  list<string>  $lines
     * @return list<array{kind: string, markdown: string, level: int, start: int, end: int}>
     */
    public static function blocks(array $lines): array
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            $start = $i;

            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
                for ($i++; $i < $count && ! str_starts_with(ltrim($lines[$i]), $fence[1]); $i++);
                $i = min($i + 1, $count);
                $blocks[] = self::block(Piece::CODE, $lines, $start, $i);

                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})(?:\s|$)/', $line, $heading) === 1) {
                $blocks[] = self::block(Piece::HEADING, $lines, $start, ++$i, strlen($heading[1]));

                continue;
            }

            if (preg_match('/^(\s*)(?:[-*+]|\d+[.)])\s+/', $line, $item) === 1) {
                // An item runs on through its indented lines.
                for ($i++; $i < $count && trim($lines[$i]) !== '' && preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $lines[$i]) !== 1 && preg_match('/^\s/', $lines[$i]) === 1; $i++);
                $blocks[] = self::block(Piece::ITEM, $lines, $start, $i, intdiv(strlen($item[1]), 2));

                continue;
            }

            $kind = match (true) {
                str_starts_with(ltrim($line), '>') => Piece::QUOTE,
                str_starts_with(ltrim($line), '|') => Piece::TABLE,
                default => Piece::PARAGRAPH,
            };

            for ($i++; $i < $count && trim($lines[$i]) !== '' && ! self::startsBlock($lines[$i], $kind); $i++);
            $blocks[] = self::block($kind, $lines, $start, $i);
        }

        return $blocks;
    }

    private static function startsBlock(string $line, string $kind): bool
    {
        if (preg_match('/^\s{0,3}(?:#{1,6}(?:\s|$)|`{3,}|~{3,})/', $line) === 1 || preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $line) === 1) {
            return true;
        }

        $quote = str_starts_with(ltrim($line), '>');
        $table = str_starts_with(ltrim($line), '|');

        return match ($kind) {
            Piece::QUOTE => ! $quote,
            Piece::TABLE => ! $table,
            default => $quote,
        };
    }

    /**
     * @param  list<string>  $lines
     * @return array{kind: string, markdown: string, level: int, start: int, end: int}
     */
    private static function block(string $kind, array $lines, int $start, int $end, int $level = 0): array
    {
        return ['kind' => $kind, 'markdown' => trim(implode("\n", array_slice($lines, $start, $end - $start))), 'level' => $level, 'start' => $start, 'end' => $end];
    }
}
