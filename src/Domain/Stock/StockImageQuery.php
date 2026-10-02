<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use DateTimeInterface;

/**
 * Which ledger records to list: in these states (any when empty), from
 * one library, used on one record (and site), inserted since a moment;
 * one page of them, newest first.
 */
final class StockImageQuery
{
    public readonly int $page;

    public readonly int $perPage;

    /**
     * @param  array<int, string>  $states
     */
    public function __construct(
        public readonly array $states = [],
        public readonly ?string $library = null,
        public readonly ?string $ownerType = null,
        public readonly int|string|null $ownerId = null,
        public readonly ?string $site = null,
        public readonly ?DateTimeInterface $since = null,
        int $page = 1,
        int $perPage = 50,
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, min(500, $perPage));
    }

    /**
     * The same filters, another page.
     */
    public function page(int $page): self
    {
        return new self($this->states, $this->library, $this->ownerType, $this->ownerId, $this->site, $this->since, $page, $this->perPage);
    }

    /**
     * Whether a record passes the filters: for stores that filter in PHP.
     */
    public function matches(StockImage $image): bool
    {
        if ($this->states !== [] && ! in_array($image->state(), $this->states, true)) {
            return false;
        }

        if ($this->library !== null && $image->library !== $this->library) {
            return false;
        }

        if ($this->ownerType !== null && $this->ownerId !== null && ! $image->isUsedOn($this->ownerType, $this->ownerId, $this->site)) {
            return false;
        }

        return $this->since === null || $image->insertedAt >= $this->since;
    }

    /**
     * Newest first (by when inserted, then by ID), and the page asked for:
     * for stores that sort in PHP.
     *
     * @param  iterable<StockImage>  $images
     */
    public function apply(iterable $images): StockImagePage
    {
        $matching = [];

        foreach ($images as $image) {
            if ($this->matches($image)) {
                $matching[] = $image;
            }
        }

        $matching = self::newestFirst($matching);

        return new StockImagePage(array_slice($matching, ($this->page - 1) * $this->perPage, $this->perPage), count($matching), $this->page, $this->perPage);
    }

    /**
     * Newest first: by when inserted, then by ID.
     *
     * @param  array<int, StockImage>  $images
     * @return array<int, StockImage>
     */
    public static function newestFirst(array $images): array
    {
        usort($images, fn (StockImage $a, StockImage $b) => [$b->insertedAt, $b->id] <=> [$a->insertedAt, $a->id]);

        return $images;
    }
}
