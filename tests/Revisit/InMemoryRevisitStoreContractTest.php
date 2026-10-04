<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\InMemoryRevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RevisitStoreContractTest;

final class InMemoryRevisitStoreContractTest extends RevisitStoreContractTest
{
    private ?InMemoryRevisitStore $store = null;

    protected function revisitStore(): RevisitStore
    {
        return $this->store ??= new InMemoryRevisitStore;
    }
}
