<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryGuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuideStoreContractTest;

final class FilamentGuideStoreTest extends GuideStoreContractTest
{
    private ?InMemoryGuideStore $store = null;

    protected function guideStore(): InMemoryGuideStore
    {
        return $this->store ??= new InMemoryGuideStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Filament;
    }
}
