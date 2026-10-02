<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * One field's value as Walk finds it in an entry: where it is, what to
 * call it ("Hero: Intro"), its key in the pattern's fill rates ("hero.intro",
 * or "" where none is kept), and the values beside it in its block.
 */
final class Visit
{
    /**
     * @param  array<string, mixed>  $siblings  The values of its block (or the entry), by handle.
     * @param  array<int, Field>  $siblingFields
     */
    public function __construct(
        public readonly Field $field,
        public readonly mixed $value,
        public readonly FieldPath $path,
        public readonly string $label,
        public readonly string $rateKey,
        public readonly array $siblings = [],
        public readonly array $siblingFields = [],
    ) {}

    public function isEmpty(): bool
    {
        return Walk::isEmpty($this->value);
    }
}
