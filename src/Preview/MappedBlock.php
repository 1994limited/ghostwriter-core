<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Preview;

/**
 * One thing the preview's locator looks for on the rendered page: a block
 * of a page builder, a top-level field, or a section of a rich-text value.
 * Sent to the locator as JSON (toArray()).
 */
final class MappedBlock
{
    public const BLOCK = 'block';

    public const FIELD = 'field';

    public const SECTION = 'section';

    /**
     * @param  string  $key  "b7" (a block), "f2" (a top-level field), "s3" (a section of rich text): what its markers carry.
     * @param  string  $kind  self::BLOCK, FIELD or SECTION.
     * @param  string  $path  Where it is in the data, as a Gaps\FieldPath string.
     * @param  string  $label  What to call it: the set's or field's name, or a section's heading.
     * @param  string|null  $parent  The key of the block, field or section it is inside.
     * @param  list<string>  $units  The unit ids it shows (not its children's).
     * @param  array<int, string>  $fields  Field index (as in its markers) => handle.
     * @param  list<string>  $assets  Basenames of the image files it shows, for the asset fallback.
     * @param  list<string>  $anchors  The first eight words of each text value, normalised, for the text fallback.
     * @param  string  $type  A block's set handle; a field's handle; '' for a section.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $path,
        public readonly string $label,
        public readonly ?string $parent = null,
        public readonly array $units = [],
        public readonly array $fields = [],
        public readonly array $assets = [],
        public readonly array $anchors = [],
        public readonly string $type = '',
    ) {}

    /**
     * @return array{key: string, kind: string, path: string, label: string, parent: string|null, type: string, units: list<string>, fields: array<int, string>, assets: list<string>, anchors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'path' => $this->path,
            'label' => $this->label,
            'parent' => $this->parent,
            'type' => $this->type,
            'units' => $this->units,
            'fields' => $this->fields,
            'assets' => $this->assets,
            'anchors' => $this->anchors,
        ];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $strings = fn (string $key) => array_values(array_map('strval', array_filter(is_array($array[$key] ?? null) ? $array[$key] : [], 'is_scalar')));
        $text = fn (string $key) => is_scalar($array[$key] ?? null) ? (string) $array[$key] : '';
        $fields = [];

        foreach (is_array($array['fields'] ?? null) ? $array['fields'] : [] as $index => $handle) {
            if (is_numeric($index) && is_scalar($handle)) {
                $fields[(int) $index] = (string) $handle;
            }
        }

        return new self(
            $text('key'),
            $text('kind'),
            $text('path'),
            $text('label'),
            is_string($array['parent'] ?? null) ? $array['parent'] : null,
            $strings('units'),
            $fields,
            $strings('assets'),
            $strings('anchors'),
            $text('type'),
        );
    }
}
