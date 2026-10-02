<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryWaitingStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\WaitingStoreContractTest;

final class WaitingStoreTest extends WaitingStoreContractTest
{
    private ?InMemoryWaitingStore $store = null;

    protected function waitingStore(): InMemoryWaitingStore
    {
        return $this->store ??= new InMemoryWaitingStore;
    }
}
