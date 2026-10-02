<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;

/**
 * Where an addon keeps the stock image ledger: Statamic one YAML file per
 * record under `content/ghostwriter/stock`, Craft and Filament a
 * `ghostwriter_stock_images` table with the usages beside it.
 *
 * There is deliberately no delete: licence records are permanent. save()
 * must pass the record through StockImage::over() with the one stored, so
 * a record's history only grows and a licensed record never goes back to
 * being a preview.
 *
 * The rules a store must keep are in tests/Contracts/StockImageStoreContract.php.
 */
interface StockImageStore
{
    /**
     * Adds the record, or saves it over the one with its ID, through
     * $image->over($stored).
     *
     * @throws Conflict when over() refuses it.
     */
    public function save(StockImage $image): StockImage;

    /**
     * Null for an ID that isn't one, or no such record.
     */
    public function find(string $id): ?StockImage;

    /**
     * The live record for an asset: the newest that isn't removed.
     */
    public function forAsset(AssetRef $asset): ?StockImage;

    /**
     * Every record of one photo from one library, newest first.
     *
     * @return array<int, StockImage>
     */
    public function forExternal(string $library, string $externalId): array;

    /**
     * Filtered, newest first, one page.
     */
    public function query(StockImageQuery $query): StockImagePage;

    /**
     * Previews last changed before the cutoff, for cleanup.
     *
     * @return array<int, StockImage>
     */
    public function previewsBefore(DateTimeInterface $cutoff): array;
}
