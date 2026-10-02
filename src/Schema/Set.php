<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

/**
 * One kind of block a page builder allows (a Matrix entry type, a Neo
 * block type, a replicator set, a Builder block). Its handle is the key
 * it sits under in the field's `sets`.
 */
final class Set
{
    /**
     * @param  array<int, Field>  $fields
     */
    public function __construct(
        public readonly string $label,
        public readonly string $instructions = '',
        public readonly array $fields = [],
    ) {}

    public function field(string $handle): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['label' => $this->label];

        if ($this->instructions !== '') {
            $array['instructions'] = $this->instructions;
        }

        $array['fields'] = array_map(fn (Field $field) => $field->toArray(), $this->fields);

        return $array;
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $fields = [];

        foreach (is_array($array['fields'] ?? null) ? $array['fields'] : [] as $field) {
            if (is_array($field)) {
                /** @var array<string, mixed> $field */
                $fields[] = Field::fromArray($field);
            }
        }

        return new self(
            is_scalar($array['label'] ?? null) ? (string) $array['label'] : '',
            is_scalar($array['instructions'] ?? null) ? (string) $array['instructions'] : '',
            $fields,
        );
    }
}
