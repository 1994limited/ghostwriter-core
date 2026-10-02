<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\StockImageStoreContractTest;

final class CraftStockImageStoreTest extends StockImageStoreContractTest
{
    private ?InMemoryStockImageStore $store = null;

    protected function stockImageStore(): InMemoryStockImageStore
    {
        return $this->store ??= new InMemoryStockImageStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
