<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Turns a plan into draft data: the shape Text\Draft::$data has, so the
 * existing build path (EntryBuilder, HouseStyle, placeholders, images)
 * runs on it unchanged. Deterministic; no model.
 *
 * - Fields the plan doesn't arrange are copied from the draft as they are.
 * - A page builder's blocks are built from their placements. A block with
 *   an `origin` (the writer's plan) starts from that block of the draft,
 *   so its images and settings come along; text the plan moved elsewhere
 *   is taken out of it.
 * - A placement of exactly the units one value had, as they are, gets
 *   that value as the draft has it. Anything else is put together from
 *   pieces, as the target field's kind takes it: one line for text,
 *   paragraphs for long text, markdown for rich text, items for a list,
 *   rows for a rows field, the assets for an image field.
 * - A rich-text field is its constructs, in order, as markdown.
 * - A field whose blocks are exactly the writer's is the draft's own
 *   value. So the writer's plan arranges to the draft itself.
 */
final class Arranger
{
    /**
     * @param  bool  $keepUntouched  Copy a field the plan leaves as the writer laid it out. Off only to test the building path.
     */
    public function __construct(private readonly bool $keepUntouched = true) {}

    /**
     * @param  Extras|array<int, mixed>  $extras  Extras, or Session::$extras.
     * @param  Draft|array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function arrange(Plan $plan, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema): array
    {
        $data = $draft instanceof Draft ? $draft->data : $draft;
        $extras = $extras instanceof Extras ? $extras : Extras::fromArray($extras);
        $content = new Content($units, $extras);
        $writer = $this->keepUntouched ? Plans::fromDraft($data, $units, $schema) : null;

        foreach ($plan->fields as $handle => $blocks) {
            $field = $schema->field($handle);

            if ($field === null) {
                continue;
            }

            if ($writer !== null && array_key_exists($handle, $data) && self::same($blocks, $writer->fields[$handle] ?? null)) {
                continue;
            }

            $original = $data[$handle] ?? null;

            if ($field->isBuilder()) {
                $data[$handle] = $this->blocks($blocks, $field, FieldPath::of($handle), is_array($original) ? $original : [], $content, $units, $data);
            } elseif (Plans::isMarkdown($field)) {
                $data[$handle] = $this->constructs($blocks, $content, $units, $data, FieldPath::of($handle));
            } else {
                $placement = ($blocks[0] ?? null)?->placements[0] ?? null;
                $value = $placement === null ? null : $this->value($placement, $field, $content, $units, $data);

                if ($value === null) {
                    unset($data[$handle]);
                } else {
                    $data[$handle] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * A plan built into entry data with EntryBuilder, as "Use this draft"
     * does with the writer's draft. House style and images are applied
     * afterwards, as before.
     *
     * @param  Extras|array<int, mixed>  $extras
     * @param  Draft|array<string, mixed>  $draft
     * @param  array<string, mixed>  $defaults
     */
    public function build(EntryBuilder $builder, Plan $plan, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema, ?Pattern $pattern = null, array $defaults = []): BuiltEntry
    {
        return $builder->build($this->arrange($plan, $units, $extras, $draft, $schema), $schema, $pattern, $defaults);
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @param  array<int|string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function blocks(array $blocks, Field $field, FieldPath $path, array $original, Content $content, Units $units, array $data): array
    {
        $originals = array_values(array_filter($original, 'is_array'));
        $out = [];

        foreach ($blocks as $block) {
            $set = $field->set($block->type);
            $base = ['type' => $block->type];
            $placed = array_map(fn (Placement $placement) => explode('/', $placement->field)[0], $block->placements);

            if ($block->origin !== null && is_array($originals[$block->origin] ?? null) && ($originals[$block->origin]['type'] ?? null) === $block->type) {
                $base = $originals[$block->origin];
                $at = $path->with(new BlockRef(null, $block->origin, $block->type));

                // Text the plan put somewhere else is not left behind.
                foreach (array_keys($base) as $key) {
                    $key = (string) $key;

                    if (! in_array($key, $placed, true) && ! isset($block->children[$key]) && $units->inBlock($at->with($key)) !== []) {
                        unset($base[$key]);
                    }
                }
            }

            foreach ($block->settings as $key => $value) {
                if (! array_key_exists($key, $base)) {
                    $base[$key] = $value;
                }
            }

            foreach ($block->placements as $placement) {
                $target = $set === null ? null : self::target($set->fields, $placement->field);

                if ($target === null) {
                    continue;
                }

                $value = $this->value($placement, $target, $content, $units, $data);

                if ($value !== null) {
                    $base = self::put($base, explode('/', $placement->field), $value);
                }
            }

            foreach ($block->children as $handle => $children) {
                $childField = $set === null ? null : self::target($set->fields, $handle);

                if ($childField !== null && $childField->isBuilder()) {
                    $base[$handle] = $this->blocks($children, $childField, $path, is_array($base[$handle] ?? null) ? $base[$handle] : [], $content, $units, $data);
                }
            }

            $out[] = $base;
        }

        return $out;
    }

    /**
     * A rich-text field's constructs, as markdown.
     *
     * @param  list<PlanBlock>  $blocks
     * @param  array<string, mixed>  $data
     */
    private function constructs(array $blocks, Content $content, Units $units, array $data, FieldPath $path): ?string
    {
        $chunks = [];

        foreach ($blocks as $block) {
            $pieces = [];

            foreach ($block->placements as $placement) {
                array_push($pieces, ...($content->resolve($placement->from, $placement->transform, $placement->options) ?? []));
            }

            $chunk = self::construct($block->type, $pieces);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        return $chunks === [] ? null : implode("\n\n", $chunks);
    }

    /**
     * One construct as markdown.
     *
     * @param  list<Piece>  $pieces
     */
    public static function construct(string $type, array $pieces): string
    {
        if (preg_match('/^h([1-6])$/', $type, $m) === 1) {
            $text = trim(implode(' ', array_map(fn (Piece $piece) => Content::plain($piece), $pieces)));

            return $text === '' ? '' : str_repeat('#', (int) $m[1]).' '.$text;
        }

        return match (true) {
            $type === 'p' => implode("\n\n", array_filter(array_map(fn (Piece $piece) => Content::plain($piece), $pieces))),
            $type === 'list' => Content::joinMarkdown(Content::transform($pieces, Transform::ParagraphsToList)),
            $type === 'quote', str_starts_with($type, 'set:') => Content::joinMarkdown(Content::transform($pieces, Transform::AsQuote)),
            default => Content::joinMarkdown($pieces),
        };
    }

    /**
     * What one placement puts in its field; null for nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function value(Placement $placement, Field $target, Content $content, Units $units, array $data): mixed
    {
        $raw = $this->asWritten($placement, $target, $units, $data);

        if ($raw !== null) {
            return $raw[0];
        }

        if ($target->files) {
            foreach ($placement->from as $ref) {
                $unit = $units->get($ref);

                if ($unit !== null && $unit->kind === UnitKind::Media) {
                    $value = self::at($data, $unit->path);

                    return $value[0] ?? $unit->assets;
                }
            }

            return null;
        }

        if ($target->kind === Kind::Rows) {
            return $this->rows($placement, $target, $content, $units, $data);
        }

        $pieces = $content->resolve($placement->from, $placement->transform, $placement->options);

        return $pieces === null ? null : self::render($pieces, $target);
    }

    /**
     * Pieces as a field of this kind takes them.
     *
     * @param  list<Piece>  $pieces
     */
    public static function render(array $pieces, Field $target): mixed
    {
        if (Plans::isMarkdown($target)) {
            return Content::joinMarkdown($pieces);
        }

        return match ($target->kind) {
            Kind::Text => trim(implode(' ', array_map(fn (Piece $piece) => str_replace("\n", ' ', Content::plain($piece)), $pieces))),
            Kind::LongText => implode("\n\n", array_map(fn (Piece $piece) => Content::plain($piece), $pieces)),
            Kind::List => array_values(array_map(fn (Piece $piece) => Content::plain($piece), $pieces)),
            default => null,
        };
    }

    /**
     * Rows from refs: a row unit as the draft has it; an extra item by its
     * parts, column by column; anything else into the first text column.
     * With `rows` in the options, each column of each row gets its ref.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(Placement $placement, Field $target, Content $content, Units $units, array $data): array
    {
        $rows = [];

        foreach ($placement->rows() as $mapping) {
            $row = [];

            foreach ($mapping as $column => $ref) {
                $field = self::target($target->fields, $column);
                $pieces = $content->resolve([$ref], $placement->transform, $placement->options);

                if ($field !== null && $pieces !== null) {
                    $row[$column] = self::render($pieces, $field);
                }
            }

            $rows[] = $row;
        }

        foreach ($placement->from as $ref) {
            $unit = $units->get($ref);

            if ($unit !== null && $unit->kind === UnitKind::Row) {
                $row = self::at($data, $unit->path);
                $rows[] = is_array($row[0] ?? null) ? $row[0] : self::columns($unit->pieces, $target);

                continue;
            }

            $pieces = $content->rowPieces($ref);

            if ($pieces !== null) {
                $rows[] = self::columns($pieces, $target);
            }
        }

        return $rows;
    }

    /**
     * Pieces into a row's columns: named pieces by name (a row's fields, an
     * extra item's parts), the rest into the first text column left.
     *
     * @param  list<Piece>  $pieces
     * @return array<string, mixed>
     */
    private static function columns(array $pieces, Field $target): array
    {
        $row = [];
        $aliases = ['text' => ['answer', 'text', 'description', 'body', 'label', 'caption', 'content'], 'question' => ['question', 'heading', 'title'], 'value' => ['value', 'number', 'figure', 'stat', 'amount'], 'attribution' => ['attribution', 'cite', 'author', 'name']];
        $free = [];

        foreach ($pieces as $piece) {
            $name = $piece->field ?? ($piece->kind === Piece::HEADING ? 'question' : 'text');
            $placed = false;

            foreach (array_merge([$name], $aliases[$name] ?? []) as $handle) {
                $column = self::target($target->fields, $handle);

                if ($column !== null && ! isset($row[$handle]) && in_array($column->kind, [Kind::Text, Kind::LongText, Kind::RichText, Kind::List], true)) {
                    $row[$handle] = self::render([new Piece(Piece::PARAGRAPH, Content::plain($piece))], $column);
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $free[] = $piece;
            }
        }

        foreach ($free as $piece) {
            foreach ($target->fields as $column) {
                if (! isset($row[$column->handle]) && in_array($column->kind, [Kind::Text, Kind::LongText, Kind::RichText], true)) {
                    $row[$column->handle] = self::render([$piece], $column);

                    break;
                }
            }
        }

        return $row;
    }

    /**
     * The draft's own value, when a placement takes exactly the units one
     * value had, whole and as they are, into a field that holds the same
     * kind of value. Wrapped, so null can't be mistaken for it.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: mixed}|null
     */
    private function asWritten(Placement $placement, Field $target, Units $units, array $data): ?array
    {
        if ($placement->transform !== Transform::AsIs || $placement->rows() !== [] || $placement->from === []) {
            return null;
        }

        $value = self::valuePath($units->get($placement->from[0]) ?? new Unit('', UnitKind::Text, FieldPath::of('?'), ''));

        foreach ($placement->from as $ref) {
            $unit = $units->get($ref);

            if ($unit === null) {
                return null;
            }

            if ($value->dotted() !== self::valuePath($unit)->dotted()) {
                return null;
            }
        }

        $all = array_values(array_map(fn (Unit $unit) => $unit->id, array_filter($units->all(), fn (Unit $unit) => self::valuePath($unit)->dotted() === $value->dotted())));

        if ($all !== $placement->from) {
            return null;
        }

        $raw = self::at($data, $value);

        if ($raw === null || ! self::holds($target, $raw[0])) {
            return null;
        }

        return $raw;
    }

    /** The value a unit is part of: a row's rows field, else its own path. */
    private static function valuePath(Unit $unit): FieldPath
    {
        if ($unit->kind === UnitKind::Row && count($unit->path->segments) > 1) {
            return new FieldPath(array_slice($unit->path->segments, 0, -1));
        }

        return $unit->path;
    }

    private static function holds(Field $target, mixed $value): bool
    {
        if ($target->files) {
            return true;
        }

        return match ($target->kind) {
            Kind::Text, Kind::LongText, Kind::RichText => is_string($value) || (is_array($value) && ! array_is_list($value)) || ($target->kind === Kind::RichText && is_array($value)),
            Kind::List, Kind::Rows => is_array($value) && array_is_list($value),
            default => false,
        };
    }

    /**
     * A value in draft data by its path, wrapped; null when it isn't there.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: mixed}|null
     */
    public static function at(array $data, FieldPath $path): ?array
    {
        $value = $data;

        foreach ($path->segments as $segment) {
            if (! is_array($value)) {
                return null;
            }

            if ($segment instanceof BlockRef) {
                $items = array_values(array_filter($value, 'is_array'));

                if (! array_key_exists($segment->index, $items)) {
                    return null;
                }

                $value = $items[$segment->index];

                continue;
            }

            if (! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return [$value];
    }

    /**
     * A set's field by a placement's field: a handle, or a path through groups.
     *
     * @param  array<int, Field>  $fields
     */
    public static function target(array $fields, string $path): ?Field
    {
        $field = null;

        foreach (explode('/', $path) as $handle) {
            $field = null;

            foreach ($fields as $candidate) {
                if ($candidate->handle === $handle) {
                    $field = $candidate;

                    break;
                }
            }

            if ($field === null) {
                return null;
            }

            $fields = $field->fields;
        }

        return $field;
    }

    /**
     * @param  array<int|string, mixed>  $array
     * @param  list<string>  $keys
     * @return array<int|string, mixed>
     */
    private static function put(array $array, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if ($key === null) {
            return $array;
        }

        $array[$key] = $keys === [] ? $value : self::put(is_array($array[$key] ?? null) ? $array[$key] : [], $keys, $value);

        return $array;
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @param  list<PlanBlock>|null  $writer
     */
    private static function same(array $blocks, ?array $writer): bool
    {
        return $writer !== null && array_map(fn (PlanBlock $block) => $block->toArray(), $blocks) === array_map(fn (PlanBlock $block) => $block->toArray(), $writer);
    }
}
