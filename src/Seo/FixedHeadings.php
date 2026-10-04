<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * What HeadingFixer made of a value: its markdown and what changed.
 */
final class FixedHeadings
{
    /**
     * @param  list<HeadingChange>  $changes
     */
    public function __construct(
        public readonly string $markdown,
        public readonly array $changes = [],
    ) {}

    public function changed(): bool
    {
        return $this->changes !== [];
    }
}
