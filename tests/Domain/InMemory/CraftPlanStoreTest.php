<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryPlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlanStoreContractTest;

final class CraftPlanStoreTest extends PlanStoreContractTest
{
    private ?InMemoryPlanStore $store = null;

    protected function planStore(): InMemoryPlanStore
    {
        return $this->store ??= new InMemoryPlanStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Craft;
    }
}
