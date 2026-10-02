<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImagePage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;

/**
 * A StockImageStore in memory, keeping each record as the array a real
 * store would write.
 */
final class InMemoryStockImageStore implements StockImageStore
{
    /** @var array<string, array<string, mixed>> */
    public array $records = [];

    public function __construct(private readonly Format $format) {}

    public function save(StockImage $image): StockImage
    {
        $image->over($this->find($image->id));
        $this->records[$image->id] = $image->toArray($this->format);

        return $image;
    }

    public function find(string $id): ?StockImage
    {
        return isset($this->records[$id]) ? StockImage::fromArray($this->records[$id], $this->format) : null;
    }

    public function forAsset(AssetRef $asset): ?StockImage
    {
        $live = array_filter($this->all(), fn (StockImage $image) => ! $image->is(StockImage::REMOVED) && $image->asset->is($asset));

        return StockImageQuery::newestFirst(array_values($live))[0] ?? null;
    }

    public function forExternal(string $library, string $externalId): array
    {
        $matching = array_filter($this->all(), fn (StockImage $image) => $image->library === $library && $image->externalId === $externalId);

        return StockImageQuery::newestFirst(array_values($matching));
    }

    public function query(StockImageQuery $query): StockImagePage
    {
        return $query->apply($this->all());
    }

    public function previewsBefore(DateTimeInterface $cutoff): array
    {
        return array_values(array_filter($this->all(), fn (StockImage $image) => $image->is(StockImage::PREVIEW) && $image->stateChangedAt() < $cutoff));
    }

    /**
     * @return array<int, StockImage>
     */
    private function all(): array
    {
        return array_values(array_map(fn (array $data) => StockImage::fromArray($data, $this->format), $this->records));
    }
}
