<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

use InvalidArgumentException;

/**
 * One field of a schema, as an addon's SchemaReader reads it from the CMS:
 * its handle, its kind and what an editor is told about it, and for
 * structured kinds what it is made of.
 *
 * - `sets`: for a page builder (`blocks`), the block types it allows, by
 *   handle. Bard keeps its sets here too, though it is `richtext`.
 * - `fields`: for `rows` and `group`, the fields each row or the group has.
 * - `type`: the CMS's own field type (`bard`, `replicator`, a Craft field
 *   class). Core only passes it to the dialects, which may look at it.
 * - `engine`: a tag for how a builder stores its blocks, for the adapter's
 *   own use (`matrix`, `neo`, `builder`). One tag means something to core:
 *   `neo-children`, the field a Neo block's child blocks sit under.
 * - `files`: the field holds files (assets, uploads), so a person picks
 *   them, or a placeholder marks them.
 * - `path`: where the value sits in the record, when it isn't the handle
 *   (Filament's state paths).
 * - `meta`: anything else the adapter keeps (an assets field's container,
 *   a table's column IDs, a rich text field's format). Core never reads
 *   it, apart from the dialects given it by the adapter.
 */
final class Field
{
    /** The engine tag of the field a Neo block's child blocks sit under. */
    public const CHILDREN = 'neo-children';

    /** Keys of a spec array that are fields of this class rather than meta. */
    private const SPEC_KEYS = ['handle', 'path', 'type', 'kind', 'display', 'instructions', 'required', 'options', 'sets', 'fields', 'engine'];

    /**
     * @param  array<int|string, string>  $options  Choices, by the value stored.
     * @param  array<string, Set>  $sets
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $handle,
        public readonly Kind $kind,
        public readonly string $label = '',
        public readonly string $instructions = '',
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly array $sets = [],
        public readonly array $fields = [],
        public readonly string $type = '',
        public readonly ?string $engine = null,
        public readonly bool $files = false,
        public readonly ?string $path = null,
        public readonly array $meta = [],
    ) {
        if ($handle === '') {
            throw new InvalidArgumentException('A field needs a handle.');
        }
    }

    public function isWritable(): bool
    {
        return $this->kind->isWritable();
    }

    /**
     * Whether its value is a list of blocks, each one of its sets: a page
     * builder, including one with nothing in it to write (a Matrix of
     * images is a reference, but still holds blocks). Bard keeps sets too,
     * but its value is a document, not a list of blocks.
     */
    public function isBuilder(): bool
    {
        return $this->kind === Kind::Blocks || ($this->sets !== [] && $this->kind !== Kind::RichText);
    }

    public function set(string $handle): ?Set
    {
        return $this->sets[$handle] ?? null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function with(?Kind $kind = null, ?array $meta = null): self
    {
        return new self($this->handle, $kind ?? $this->kind, $this->label, $this->instructions, $this->required, $this->options, $this->sets, $this->fields, $this->type, $this->engine, $this->files, $this->path, $meta ?? $this->meta);
    }

    /**
     * From the array an addon's SchemaReader produced before core (handle,
     * type, kind, display, instructions, required, options, sets, fields,
     * engine, path, and anything else). A field with an `images` key holds
     * files, as all three readers mark assets and uploads that way; every
     * other key is kept in `meta`.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function fromSpec(array $spec): self
    {
        $kind = Kind::tryFrom(is_string($spec['kind'] ?? null) ? $spec['kind'] : '')
            ?? throw new InvalidArgumentException('Field "'.self::string($spec['handle'] ?? '?').'" has no kind core knows: '.self::string($spec['kind'] ?? '').'.');

        $sets = [];

        foreach (is_array($spec['sets'] ?? null) ? $spec['sets'] : [] as $handle => $set) {
            if (is_array($set)) {
                $sets[(string) $handle] = new Set(
                    self::string($set['display'] ?? ''),
                    self::string($set['instructions'] ?? ''),
                    self::specs(is_array($set['fields'] ?? null) ? $set['fields'] : []),
                );
            }
        }

        return new self(
            handle: self::string($spec['handle'] ?? ''),
            kind: $kind,
            label: self::string($spec['display'] ?? ''),
            instructions: self::string($spec['instructions'] ?? ''),
            required: (bool) ($spec['required'] ?? false),
            options: self::options($spec['options'] ?? []),
            sets: $sets,
            fields: self::specs(is_array($spec['fields'] ?? null) ? $spec['fields'] : []),
            type: self::string($spec['type'] ?? ''),
            engine: is_string($spec['engine'] ?? null) ? $spec['engine'] : null,
            files: array_key_exists('images', $spec),
            path: is_string($spec['path'] ?? null) ? $spec['path'] : null,
            meta: array_diff_key($spec, array_flip(self::SPEC_KEYS)),
        );
    }

    /**
     * Back to the addons' spec array: what fromSpec() read, in the same
     * keys, for code that still works on arrays (EntrySimplifier,
     * EntryMerger, an adapter's own writers).
     *
     * @return array<string, mixed>
     */
    public function toSpec(): array
    {
        $spec = ['handle' => $this->handle];

        if ($this->path !== null) {
            $spec['path'] = $this->path;
        }

        $spec += ['type' => $this->type, 'kind' => $this->kind->value];

        if ($this->engine !== null) {
            $spec['engine'] = $this->engine;
        }

        $spec += ['display' => $this->label, 'instructions' => $this->instructions, 'required' => $this->required];

        if ($this->options !== [] || in_array($this->kind, [Kind::Choice, Kind::Choices], true)) {
            $spec['options'] = $this->options;
        }

        $spec += $this->meta;

        if ($this->sets !== []) {
            $spec['sets'] = array_map(fn (Set $set) => [
                'display' => $set->label,
                'instructions' => $set->instructions,
                'fields' => array_map(fn (Field $field) => $field->toSpec(), $set->fields),
            ], $this->sets);
        }

        if ($this->fields !== []) {
            $spec['fields'] = array_map(fn (Field $field) => $field->toSpec(), $this->fields);
        }

        return $spec;
    }

    /**
     * Core's own array form, as the fixtures store it: only what differs
     * from the defaults.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['handle' => $this->handle, 'kind' => $this->kind->value];

        foreach ([
            'label' => [$this->label, ''],
            'instructions' => [$this->instructions, ''],
            'required' => [$this->required, false],
            'options' => [$this->options, []],
            'type' => [$this->type, ''],
            'engine' => [$this->engine, null],
            'files' => [$this->files, false],
            'path' => [$this->path, null],
            'meta' => [$this->meta, []],
        ] as $key => [$value, $default]) {
            if ($value !== $default) {
                $array[$key] = $value;
            }
        }

        if ($this->sets !== []) {
            $array['sets'] = array_map(fn (Set $set) => $set->toArray(), $this->sets);
        }

        if ($this->fields !== []) {
            $array['fields'] = array_map(fn (Field $field) => $field->toArray(), $this->fields);
        }

        return $array;
    }

    /**
     * @param  array<string, mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $kind = Kind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '')
            ?? throw new InvalidArgumentException('Field "'.self::string($array['handle'] ?? '?').'" has no kind core knows.');

        $sets = [];

        foreach (is_array($array['sets'] ?? null) ? $array['sets'] : [] as $handle => $set) {
            if (is_array($set)) {
                $sets[(string) $handle] = Set::fromArray($set);
            }
        }

        return new self(
            handle: self::string($array['handle'] ?? ''),
            kind: $kind,
            label: self::string($array['label'] ?? ''),
            instructions: self::string($array['instructions'] ?? ''),
            required: (bool) ($array['required'] ?? false),
            options: self::options($array['options'] ?? []),
            sets: $sets,
            fields: array_values(array_map(fn (array $field) => self::fromArray($field), array_filter(is_array($array['fields'] ?? null) ? $array['fields'] : [], 'is_array'))),
            type: self::string($array['type'] ?? ''),
            engine: is_string($array['engine'] ?? null) ? $array['engine'] : null,
            files: (bool) ($array['files'] ?? false),
            path: is_string($array['path'] ?? null) ? $array['path'] : null,
            meta: self::stringKeys($array['meta'] ?? []),
        );
    }

    /**
     * @param  array<mixed>  $specs
     * @return array<int, Field>
     */
    private static function specs(array $specs): array
    {
        return array_values(array_map(fn (array $spec) => self::fromSpec(self::stringKeys($spec)), array_filter($specs, 'is_array')));
    }

    /**
     * @return array<int|string, string>
     */
    private static function options(mixed $options): array
    {
        $out = [];

        foreach (is_array($options) ? $options : [] as $value => $label) {
            $out[$value] = self::string($label);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeys(mixed $array): array
    {
        $out = [];

        foreach (is_array($array) ? $array : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
