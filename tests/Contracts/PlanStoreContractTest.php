<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run PlanStoreContract against an addon's own implementation.
 */
abstract class PlanStoreContractTest extends TestCase
{
    use PlanStoreContract;
}
