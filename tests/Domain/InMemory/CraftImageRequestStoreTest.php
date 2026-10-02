<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ImageRequestStoreContractTest;

final class CraftImageRequestStoreTest extends ImageRequestStoreContractTest
{
    private ?InMemoryImageRequestStore $store = null;

    protected function imageRequestStore(): InMemoryImageRequestStore
    {
        return $this->store ??= new InMemoryImageRequestStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
