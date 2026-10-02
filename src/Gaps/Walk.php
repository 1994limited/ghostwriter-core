<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use Generator;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Walks an entry's values with its schema, field by field in form order:
 * each field itself, then what is inside it (a page builder's blocks, a
 * table's rows, a group's fields). Disabled blocks are left out: they
 * won't be published.
 */
final class Walk
{
    /**
     * @return Generator<int, Visit>
     */
    public static function entry(Schema $schema, EntryData $entry): Generator
    {
        yield from self::fields($schema->fields, $entry->values, null, '', null);
    }

    /**
     * The text in a value, as markdown for rich text and one item per line
     * for a list; null for a field that holds no text.
     */
    public static function text(Visit $visit, RichTextDialect $richText): ?string
    {
        $value = $visit->value;

        return match ($visit->field->kind) {
            Kind::Text, Kind::LongText => is_scalar($value) ? (string) $value : null,
            Kind::RichText => $value === null || $value === '' || $value === [] ? null : $richText->toMarkdown($value, $visit->field),
            Kind::List => is_array($value) ? implode("\n", array_map(fn ($item) => (string) $item, array_filter($value, 'is_scalar'))) : (is_string($value) ? $value : null),
            default => null,
        };
    }

    public static function isEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::isEmpty($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null;
    }

    /**
     * @param  array<int, Field>  $fields
     * @param  array<mixed>  $values
     * @return Generator<int, Visit>
     */
    private static function fields(array $fields, array $values, ?FieldPath $at, string $label, ?string $type): Generator
    {
        $siblings = [];

        foreach ($values as $key => $value) {
            $siblings[(string) $key] = $value;
        }

        foreach ($fields as $field) {
            $value = $values[$field->handle] ?? null;
            $path = $at === null ? FieldPath::of($field->handle) : $at->with($field->handle);
            $name = ($label === '' ? '' : "{$label}: ").($field->label !== '' ? $field->label : $field->handle);
            $rateKey = $type === null ? ($at === null ? $field->handle : '') : "{$type}.{$field->handle}";

            yield new Visit($field, $value, $path, $name, $rateKey, $siblings, $fields);

            if (! is_array($value)) {
                continue;
            }

            if ($field->isBuilder()) {
                $index = 0;

                foreach ($value as $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    $blockType = is_scalar($block['type'] ?? null) ? (string) $block['type'] : '';
                    $set = $field->set($blockType);
                    $position = $index++;

                    if ($set === null || ($block['enabled'] ?? true) === false) {
                        continue;
                    }

                    $id = $block['id'] ?? null;
                    $ref = new BlockRef(is_int($id) || is_string($id) ? $id : null, $position, $blockType);

                    yield from self::fields($set->fields, $block, $path->with($ref), $set->label !== '' ? $set->label : $blockType, $blockType);
                }
            } elseif ($field->kind === Kind::Rows) {
                foreach (array_values(array_filter($value, 'is_array')) as $i => $row) {
                    $id = $row['id'] ?? null;

                    yield from self::fields($field->fields, $row, $path->with(new BlockRef(is_int($id) || is_string($id) ? $id : null, $i)), $name.' '.($i + 1), null);
                }
            } elseif ($field->kind === Kind::Group) {
                yield from self::fields($field->fields, $value, $path, $name, null);
            }
        }
    }
}
