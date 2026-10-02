<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run WaitingStoreContract against an addon's own implementation.
 */
abstract class WaitingStoreContractTest extends TestCase
{
    use WaitingStoreContract;
}
