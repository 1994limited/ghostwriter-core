<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

/**
 * One page of ledger records, newest first, and how many there are in all.
 */
final class StockImagePage
{
    /**
     * @param  array<int, StockImage>  $images
     */
    public function __construct(
        public readonly array $images,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function hasMore(): bool
    {
        return $this->page * $this->perPage < $this->total;
    }
}
