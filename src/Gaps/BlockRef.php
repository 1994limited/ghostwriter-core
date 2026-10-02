<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * One block (or row) along a FieldPath: its ID where the CMS keeps one in
 * the entry's data, so a gap stays with its block when blocks are
 * reordered, its position among its siblings, and its type (empty for a
 * row).
 */
final class BlockRef
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly int $index,
        public readonly string $type = '',
    ) {}

    /** "#a1b2" with an ID, else the position: "1". */
    public function toString(): string
    {
        return $this->id !== null && $this->id !== '' ? '#'.$this->id : (string) $this->index;
    }
}
