<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain\InMemory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemorySessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SessionStoreContractTest;

final class FilamentSessionStoreTest extends SessionStoreContractTest
{
    private ?InMemorySessionStore $store = null;

    protected function sessionStore(): InMemorySessionStore
    {
        return $this->store ??= new InMemorySessionStore($this->storeFormat());
    }

    protected function storeFormat(): Format
    {
        return Format::Filament;
    }
}
