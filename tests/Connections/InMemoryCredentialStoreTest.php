<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\InMemoryCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\CredentialStoreContractTest;

final class InMemoryCredentialStoreTest extends CredentialStoreContractTest
{
    private ?InMemoryCredentialStore $store = null;

    protected function contractStore(): CredentialStore
    {
        return $this->store ??= new InMemoryCredentialStore;
    }

    protected function contractRaw(string $name): ?string
    {
        $value = $this->contractStore()->get($name);

        return $value === null ? null : 'in memory';
    }

    protected function contractEncrypts(): bool
    {
        return false;
    }
}
