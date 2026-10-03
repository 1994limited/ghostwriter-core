<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * What goes into one field of a planned block: units, pieces of units or
 * extra items, in order, with one transform.
 *
 * Refs:
 * - `u7`: a whole unit; `u7#2`: its second piece; `u7#2:lead` and
 *   `u7#2:rest`: a bold lead-in and the rest of that paragraph;
 * - `x1.2`: an extra item; `x1.2.question`: one of its parts.
 *
 * `field` is the set's field handle ("heading"), a path through a group
 * ("seo/description"), `@body` for a rich-text construct, or the field's
 * own handle for a top-level field.
 *
 * Options: `level` (a heading level), and `rows` for a rows field: a list
 * of rows, each mapping a column's handle to a ref.
 */
final class Placement
{
    public const BODY = '@body';

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $field,
        public readonly array $from,
        public readonly Transform $transform = Transform::AsIs,
        public readonly array $options = [],
    ) {}

    /**
     * Every ref it uses, rows included, as often as it uses them.
     *
     * @return list<string>
     */
    public function refs(): array
    {
        $refs = $this->from;

        foreach ($this->rows() as $row) {
            foreach ($row as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (is_array($this->options['rows'] ?? null) ? $this->options['rows'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];

            foreach ($row as $column => $ref) {
                if (is_string($column) && is_string($ref) && $ref !== '') {
                    $clean[$column] = $ref;
                }
            }

            if ($clean !== []) {
                $rows[] = $clean;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $from
     */
    public function with(?array $from = null): self
    {
        return new self($this->field, $from ?? $this->from, $this->transform, $this->options);
    }

    /**
     * @return array{field: string, from: list<string>, transform?: string, options?: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'from' => $this->from]
            + ($this->transform !== Transform::AsIs ? ['transform' => $this->transform->value] : [])
            + ($this->options !== [] ? ['options' => $this->options] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $options = [];

        foreach (is_array($array['options'] ?? null) ? $array['options'] : [] as $key => $value) {
            $options[(string) $key] = $value;
        }

        return new self(
            is_scalar($array['field'] ?? null) ? (string) $array['field'] : '',
            array_values(array_map('strval', array_filter(is_array($array['from'] ?? null) ? $array['from'] : [], 'is_scalar'))),
            Transform::tryFrom(is_string($array['transform'] ?? null) ? $array['transform'] : '') ?? Transform::AsIs,
            $options,
        );
    }
}
