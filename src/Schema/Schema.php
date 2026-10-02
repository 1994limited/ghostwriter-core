<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * What a kind of entry is made of: its fields, in the order an editor sees
 * them, as the addon's SchemaReader read them from the CMS (a Statamic
 * blueprint, a Craft field layout, a Filament form).
 *
 * @implements IteratorAggregate<int, Field>
 */
final class Schema implements Countable, IteratorAggregate
{
    /** @var array<int, Field> */
    public readonly array $fields;

    /**
     * @param  array<int, Field>  $fields
     */
    public function __construct(array $fields = [])
    {
        $this->fields = array_values($fields);
    }

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
     * @return array<int, Field>
     */
    public function writable(): array
    {
        return array_values(array_filter($this->fields, fn (Field $field) => $field->isWritable()));
    }

    /**
     * From the field arrays an addon's SchemaReader produced before core.
     * See Field::fromSpec().
     *
     * @param  array<mixed>  $specs
     */
    public static function fromSpecs(array $specs): self
    {
        $fields = [];

        foreach ($specs as $spec) {
            if (is_array($spec)) {
                /** @var array<string, mixed> $spec */
                $fields[] = Field::fromSpec($spec);
            }
        }

        return new self($fields);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toSpecs(): array
    {
        return array_map(fn (Field $field) => $field->toSpec(), $this->fields);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Field $field) => $field->toArray(), $this->fields);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $fields = [];

        foreach ($array as $field) {
            if (is_array($field)) {
                /** @var array<string, mixed> $field */
                $fields[] = Field::fromArray($field);
            }
        }

        return new self($fields);
    }

    public function count(): int
    {
        return count($this->fields);
    }

    /**
     * @return Traversable<int, Field>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->fields);
    }
}
