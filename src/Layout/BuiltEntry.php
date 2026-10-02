<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * A draft built into entry data, with what the person should be told: keys
 * the schema has no place for, options that don't exist, a boilerplate
 * block whose drafted words were replaced, and the references still to
 * choose.
 *
 * For the gap list (Gaps\SessionGaps) the same places are also kept with
 * where they are, as FieldPath strings:
 *
 * - `asks`: facts the writer marked as still to add (`[[ask: …]]`) in a
 *   field that can't hold text, such as a number or a date. Text fields
 *   keep their markers in the data.
 * - `toFill`: the references a kind of block usually has that a person
 *   still has to choose (the "Still to choose by hand" note).
 */
final class BuiltEntry
{
    /**
     * @param  array<string, mixed>  $data  In EntryData's shape.
     * @param  array<int, string>  $notes
     * @param  list<array{path: string, label: string, hint: string}>  $asks
     * @param  list<array{path: string, label: string}>  $toFill
     */
    public function __construct(
        public readonly array $data,
        public readonly array $notes = [],
        public readonly array $asks = [],
        public readonly array $toFill = [],
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
