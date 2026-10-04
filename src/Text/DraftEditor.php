<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\MarkdownSections;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes one unit's new text into a draft's data, and the data back out
 * as the draft's YAML, the way the addons' hand edits write it. Revisions
 * and "Put it back" use it, so the draft only ever changes where a unit
 * is. No model.
 *
 * - A rich-text section (a unit with a `part`) replaces that section of
 *   the field's markdown; the other sections stay as they are.
 * - A text value takes the text on one line; a long text, the text.
 * - A list takes one item per line ("- " bullets are taken off).
 * - A row takes its fields' values in order, one per paragraph.
 * - A media unit has no text to write.
 */
final class DraftEditor
{
    /**
     * The data with the unit's value (or section of it) replaced.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the unit isn't in the data, or the text doesn't fit its shape.
     */
    public function set(array $data, Unit $unit, string $markdown): array
    {
        $markdown = trim($markdown);

        return self::put($data, $unit->path->segments, function (mixed $value) use ($unit, $markdown) {
            return match (true) {
                $unit->kind === UnitKind::Media => throw new InvalidArgumentException('An image has no text to change.'),
                $unit->kind === UnitKind::Row => self::row($value, $unit, $markdown),
                $unit->part !== null => self::section($value, $unit->part, $markdown),
                $unit->kind === UnitKind::List && is_array($value) => self::items($markdown),
                $unit->kind === UnitKind::Text => trim((string) preg_replace('/\s*\n\s*/u', ' ', $markdown)),
                default => $markdown,
            };
        });
    }

    /**
     * The data as a draft's YAML, as the addons write it.
     *
     * @param  array<string, mixed>  $data
     */
    public function dump(array $data): string
    {
        return trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string|BlockRef>  $segments
     * @param  callable(mixed): mixed  $change
     * @return array<mixed>
     */
    private static function put(array $data, array $segments, callable $change): array
    {
        $segment = array_shift($segments);

        if ($segment instanceof BlockRef) {
            $keys = array_keys(array_filter($data, 'is_array'));
            $key = $keys[$segment->index] ?? throw new InvalidArgumentException('The draft has no block '.$segment->index.' there.');
        } else {
            $key = (string) $segment;

            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("The draft has no \"{$key}\".");
            }
        }

        if ($segments === []) {
            $data[$key] = $change($data[$key]);

            return $data;
        }

        if (! is_array($data[$key])) {
            throw new InvalidArgumentException("The draft's \"{$key}\" holds no blocks.");
        }

        $data[$key] = self::put($data[$key], $segments, $change);

        return $data;
    }

    /** A rich-text value with one section replaced. */
    private static function section(mixed $value, int $part, string $markdown): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The draft has no text there.');
        }

        $sections = MarkdownSections::split($value);

        if (! isset($sections[$part])) {
            throw new InvalidArgumentException('The draft has no section '.$part.' there.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $texts = [];

        foreach ($sections as $i => $section) {
            $end = $sections[$i + 1]['line'] ?? count($lines);
            $texts[] = $i === $part ? $markdown : trim(implode("\n", array_slice($lines, $section['line'], $end - $section['line'])));
        }

        return implode("\n\n", array_filter($texts, fn (string $text) => $text !== ''));
    }

    /**
     * @return list<string>
     */
    private static function items(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        return array_values(array_filter(array_map(fn (string $line) => trim((string) preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/u', '', $line)), $lines), fn (string $line) => $line !== ''));
    }

    /**
     * A row with its text fields set from the paragraphs, in the unit's order.
     *
     * @return array<mixed>
     */
    private static function row(mixed $value, Unit $unit, string $markdown): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The draft has no row there.');
        }

        $fields = array_values(array_filter($unit->pieces, fn (Piece $piece) => $piece->kind === Piece::FIELD && $piece->field !== null));
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $markdown) ?: []), fn (string $text) => $text !== ''));

        if (count($paragraphs) !== count($fields)) {
            throw new InvalidArgumentException('A row takes one paragraph for each of its fields.');
        }

        foreach ($fields as $i => $piece) {
            $value[(string) $piece->field] = is_array($value[$piece->field] ?? null) ? self::items($paragraphs[$i]) : $paragraphs[$i];
        }

        return $value;
    }
}
