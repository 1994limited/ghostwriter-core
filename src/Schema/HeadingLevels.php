<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

/**
 * The heading levels a field's editor can show, as the addon's
 * SchemaReader recorded them in the field spec under `headings` (kept in
 * Field::$meta): a Bard field's heading buttons, a CKEditor field's
 * heading levels, a Filament RichEditor's toolbar.
 *
 * - `headings` a list of 1–6: those levels, sorted.
 * - `headings` an empty list: the editor shows no headings at all.
 * - No `headings` on a rich-text or markdown field: every level (a
 *   Markdown field, a plain HTML field, an addon that doesn't say).
 * - Any other field: none; it holds no headings.
 */
final class HeadingLevels
{
    public const ALL = [1, 2, 3, 4, 5, 6];

    /** The key in a field spec (and so in Field::$meta). */
    public const META = 'headings';

    /**
     * @return list<int>
     */
    public static function allowed(Field $field): array
    {
        if (! self::holdsHeadings($field)) {
            return [];
        }

        $levels = $field->meta[self::META] ?? null;

        if (! is_array($levels)) {
            return self::ALL;
        }

        return self::normalise($levels);
    }

    /** Whether the field holds headings at all: rich text, or long text stored as markdown. */
    public static function holdsHeadings(Field $field): bool
    {
        return $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));
    }

    /**
     * Levels as a sorted list of ints 1–6, whatever they were given as
     * (`'h2'`, `2`, `'2'`).
     *
     * @param  array<mixed>  $levels
     * @return list<int>
     */
    public static function normalise(array $levels): array
    {
        $out = [];

        foreach ($levels as $level) {
            if (is_string($level) && preg_match('/^h?([1-6])$/i', trim($level), $m) === 1) {
                $level = (int) $m[1];
            }

            if (is_int($level) && $level >= 1 && $level <= 6) {
                $out[$level] = $level;
            }
        }

        ksort($out);

        return array_values($out);
    }
}
