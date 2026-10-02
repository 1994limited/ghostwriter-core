<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run SessionStoreContract against an addon's own implementation.
 */
abstract class SessionStoreContractTest extends TestCase
{
    use SessionStoreContract;
}
