<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run StockImageStoreContract against an addon's own implementation.
 */
abstract class StockImageStoreContractTest extends TestCase
{
    use StockImageStoreContract;
}
