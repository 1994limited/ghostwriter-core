<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryKindStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\KindStoreContractTest;

final class FilamentKindStoreTest extends KindStoreContractTest
{
    private ?InMemoryKindStore $store = null;

    protected function kindStore(): InMemoryKindStore
    {
        return $this->store ??= new InMemoryKindStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Filament;
    }
}
