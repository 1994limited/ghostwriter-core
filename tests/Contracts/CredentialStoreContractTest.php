<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run CredentialStoreContract against an addon's own store.
 */
abstract class CredentialStoreContractTest extends TestCase
{
    use CredentialStoreContract;
}
