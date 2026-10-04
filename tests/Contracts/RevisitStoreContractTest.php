<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run RevisitStoreContract against an addon's own implementation.
 */
abstract class RevisitStoreContractTest extends TestCase
{
    use RevisitStoreContract;
}
