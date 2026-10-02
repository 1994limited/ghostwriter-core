<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * A kind of entry the kind finder found: entries built the same way.
 */
final class FoundKind
{
    /**
     * @param  array<int, int|string>  $examples  IDs of the first few entries, to model a new kind on.
     * @param  array<int, string>  $titles  Every entry's title, newest first.
     * @param  array<int, string>  $blocks  The block types the first entry was built from, in order.
     */
    public function __construct(
        public readonly string $label,
        public readonly int $count,
        public readonly array $examples,
        public readonly array $titles,
        public readonly array $blocks,
    ) {}

    /**
     * The array the addons' KindFinders returned.
     *
     * @return array{label: string, count: int, examples: array<int, int|string>, titles: array<int, string>, blocks: array<int, string>}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'count' => $this->count, 'examples' => $this->examples, 'titles' => $this->titles, 'blocks' => $this->blocks];
    }
}
