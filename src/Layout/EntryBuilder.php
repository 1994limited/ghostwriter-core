<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Turns a draft into the data an entry holds, guided by the schema:
 * markdown becomes rich text as the field stores it (the dialect's
 * business), choices are checked against their options, blocks against the
 * builder's sets, and any value the draft left out that is a house default
 * in this group is filled in.
 *
 * Anything in the draft that the schema has no place for is dropped and
 * reported, never saved. The result is plain data in EntryData's shape; the
 * adapter turns it into what its CMS's fields take.
 *
 * Facts the writer marked as still to add (`[[ask: …]]`, Gaps\Markers) are
 * kept in text as they are, with near misses put right. One written into a
 * field that can't hold text (a number, a choice, a date) can't be kept
 * there, so it is listed in BuiltEntry::$asks with where it was meant to go.
 */
final class EntryBuilder
{
    /** @var array<int, string> */
    private array $notes = [];

    /** @var array<int, string> */
    private array $toFill = [];

    /** @var list<array{path: string, label: string}> */
    private array $places = [];

    /** @var list<array{path: string, label: string, hint: string}> */
    private array $asks = [];

    public function __construct(
        private readonly LayoutOptions $options = new LayoutOptions,
        private readonly RichTextDialect $richText = new HtmlDialect,
    ) {}

    /**
     * @param  array<string, mixed>  $draft  The draft's data (Text\Draft::$data).
     * @param  Pattern|null  $pattern  What the group usually does; none when an existing entry is being revised.
     * @param  array<string, mixed>  $defaults  The kind's own defaults, which win over the group's.
     */
    public function build(array $draft, Schema $schema, ?Pattern $pattern = null, array $defaults = []): BuiltEntry
    {
        $this->notes = [];
        $this->toFill = [];
        $this->places = [];
        $this->asks = [];

        $data = $this->fields($draft, $schema->fields, $pattern->blocks ?? []);

        if ($this->toFill) {
            $this->notes[] = 'Still to choose by hand: '.implode('; ', array_unique($this->toFill)).'.';
        }

        if ($this->asks) {
            $this->notes[] = 'Still to add by hand: '.implode('; ', array_unique(array_map(fn (array $ask) => "{$ask['label']} ({$ask['hint']})", $this->asks))).'.';
        }

        // The kind's own defaults win over what the group usually does.
        foreach ($defaults + ($pattern->fixed ?? []) as $key => $value) {
            $data[$key] ??= $this->copy($value);
        }

        return new BuiltEntry($data, $this->notes, $this->asks, $this->places);
    }

    /**
     * Notes and places to fill are gathered as it goes.
     *
     * @phpstan-impure
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, Field>  $fields
     * @param  array<string, array<string, mixed>>  $blockPatterns
     * @return array<string, mixed>
     */
    private function fields(array $values, array $fields, array $blockPatterns = [], ?FieldPath $at = null, string $label = ''): array
    {
        $data = [];
        $known = array_map(fn (Field $field) => $field->handle, $fields);

        foreach (array_diff(array_keys($values), $known, ['type']) as $stray) {
            $this->notes[] = "\"{$stray}\" is not a field here and was left out.";
        }

        foreach ($fields as $field) {
            if (! array_key_exists($field->handle, $values)) {
                continue;
            }

            $path = $at === null ? FieldPath::of($field->handle) : $at->with($field->handle);
            $name = ($label === '' ? '' : "{$label}: ").($field->label !== '' ? $field->label : $field->handle);

            // A fact meant for a field that can't hold text: kept as a gap.
            if (! in_array($field->kind, [Kind::Text, Kind::LongText, Kind::RichText, Kind::List], true) && is_string($values[$field->handle]) && ($asked = Markers::asks(Markers::normalise($values[$field->handle]))) !== []) {
                foreach ($asked as $ask) {
                    $this->asks[] = ['path' => $path->toString(), 'label' => $name, 'hint' => $ask['hint']];
                }

                continue;
            }

            if (! $field->isWritable()) {
                continue;
            }

            $value = $this->value($values[$field->handle], $field, $blockPatterns[$field->handle] ?? [], $path, $name);

            if ($value !== null) {
                $data[$field->handle] = $value;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $pattern
     */
    private function value(mixed $value, Field $field, array $pattern, FieldPath $path, string $name): mixed
    {
        return match ($field->kind) {
            Kind::Text => is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', Markers::normalise((string) $value)) ?? '') : null,
            Kind::LongText => is_scalar($value) ? trim(Markers::normalise((string) $value)) : null,
            Kind::RichText => is_scalar($value) ? $this->richText->fromMarkdown(trim(Markers::normalise((string) $value)), $field) : null,
            Kind::Choice => $this->choice($value, $field),
            Kind::Choices => array_values(array_filter(array_map(fn ($v) => $this->choice($v, $field), (array) $value), fn ($v) => $v !== null)),
            Kind::Toggle => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            Kind::Number => is_numeric($value) ? $value + 0 : null,
            Kind::List => array_values(array_map(fn ($item) => Markers::normalise((string) $item), array_filter((array) $value, 'is_scalar'))),
            Kind::Blocks => $this->blocks((array) $value, $field, $pattern, $path, $name),
            Kind::Rows => $this->rows((array) $value, $field, $path, $name),
            Kind::Group => is_array($value) ? $this->fields($value, $field->fields, [], $path, $name) : null,
            Kind::Reference => null,
        };
    }

    private function choice(mixed $value, Field $field): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $options = $field->options;

        if ($options === [] || array_key_exists($value, $options)) {
            return $value;
        }

        // The model may have written the label rather than the key.
        $key = array_search(strtolower($value), array_map('strtolower', $options), true);

        if ($key !== false) {
            return (string) $key;
        }

        $this->notes[] = "\"{$value}\" is not an option for {$field->handle} and was left out.";

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows, Field $field, FieldPath $path, string $name): array
    {
        $out = [];

        foreach (array_values(array_filter($rows, 'is_array')) as $i => $row) {
            $id = $this->newId();
            $out[] = $id + $this->fields($row, $field->fields, [], $path->with(new BlockRef($id['id'] ?? null, $i)), $name.' '.($i + 1));
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $blocks
     * @param  array<string, mixed>  $pattern
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $blocks, Field $field, array $pattern, FieldPath $path, string $name): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            $set = is_string($type) ? $field->set($type) : null;

            if (! is_array($block) || ! is_string($type) || $set === null) {
                $this->notes[] = 'A block of type "'.(is_scalar($type) ? $type : '?').'" '.$this->options->unknownBlock.' '.$field->handle.' and was left out.';

                continue;
            }

            // The block's ID is made after its fields', as it always was, so
            // the places found inside it are named by position until then.
            $byPosition = $path->with(new BlockRef(null, count($out), $type));
            $asks = count($this->asks);
            $places = count($this->places);
            $fields = $this->fields($block, $set->fields, [], $byPosition, $set->label);

            // House defaults for this block: its usual settings, and for a
            // boilerplate block its usual content too. A boilerplate block
            // is always the copy, whatever the writer put in it: its wording
            // is not the writer's to change.
            $copied = in_array($type, (array) ($pattern['boilerplate'] ?? []), true);

            $fixed = (array) ($pattern['fixed'][$type] ?? []);

            $changed = array_filter(array_intersect_key($block, $fixed), fn ($value, $key) => ! is_string($value) || trim($value) !== $fixed[$key], ARRAY_FILTER_USE_BOTH);

            if ($copied && $changed !== []) {
                $this->notes[] = $set->label.' is the same on every entry here, so its usual content was used in place of what was drafted.';
            }

            foreach ($fixed as $key => $value) {
                if ($copied || ! isset($fields[$key])) {
                    $fields[$key] = $this->copy($value);
                }
            }

            // Images, links and chosen entries are a person's to pick. Name
            // the ones this kind of entry normally has, so none is missed.
            foreach ($set->fields as $setField) {
                $expected = in_array($setField->handle, (array) ($pattern['used'][$type] ?? []), true);

                if ($expected && ! $setField->isWritable() && ! isset($fields[$setField->handle])) {
                    $this->toFill[] = $set->label.': '.($setField->label ?: $setField->handle);
                    $this->places[] = ['path' => $byPosition->with($setField->handle)->toString(), 'label' => $set->label.': '.($setField->label ?: $setField->handle)];
                }
            }

            $id = $this->newId();
            $this->renamePaths($asks, $places, $byPosition, $path->with(new BlockRef($id['id'] ?? null, count($out), $type)));

            $out[] = $id + ['type' => $type, 'enabled' => true] + $fields;
        }

        return $out;
    }

    /**
     * Places found inside a block before it had its ID, renamed to it.
     */
    private function renamePaths(int $asks, int $places, FieldPath $before, FieldPath $after): void
    {
        $renamed = [];

        foreach ($this->asks as $i => $ask) {
            $renamed[] = ['path' => $i >= $asks ? self::renamed($ask['path'], $before, $after) : $ask['path']] + $ask;
        }

        $this->asks = $renamed;
        $renamed = [];

        foreach ($this->places as $i => $place) {
            $renamed[] = ['path' => $i >= $places ? self::renamed($place['path'], $before, $after) : $place['path']] + $place;
        }

        $this->places = $renamed;
    }

    private static function renamed(string $path, FieldPath $before, FieldPath $after): string
    {
        $old = $before->toString().'/';

        return str_starts_with($path, $old) ? $after->toString().'/'.substr($path, strlen($old)) : $path;
    }

    /**
     * @return array<string, string>
     */
    private function newId(): array
    {
        return $this->options->newId !== null ? ['id' => ($this->options->newId)()] : [];
    }

    /**
     * Copied content keeps its shape but not its IDs: those blocks and rows
     * belong to the entry they were copied from. Where the CMS keeps IDs in
     * the data, the copies get new ones; otherwise they have none and the
     * CMS makes them.
     */
    private function copy(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->options->newId === null) {
            unset($value['id']);

            return array_map(fn ($item) => $this->copy($item), $value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $key === 'id' && is_string($item) ? ($this->options->newId)() : $this->copy($item);
        }

        return $value;
    }
}
