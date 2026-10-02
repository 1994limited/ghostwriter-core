<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * A draft built into entry data, with what the person should be told: keys
 * the schema has no place for, options that don't exist, a boilerplate
 * block whose drafted words were replaced, and the references still to
 * choose.
 */
final class BuiltEntry
{
    /**
     * @param  array<string, mixed>  $data  In EntryData's shape.
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly array $data,
        public readonly array $notes = [],
    ) {}

    /**
     * The array the addons' EntryBuilders returned.
     *
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    public function toArray(): array
    {
        return ['data' => $this->data, 'notes' => $this->notes];
    }
}
