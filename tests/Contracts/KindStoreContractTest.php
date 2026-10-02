<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run KindStoreContract against an addon's own implementation.
 */
abstract class KindStoreContractTest extends TestCase
{
    use KindStoreContract;
}
