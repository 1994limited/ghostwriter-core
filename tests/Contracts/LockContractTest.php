<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run LockContract against an addon's own implementation.
 */
abstract class LockContractTest extends TestCase
{
    use LockContract;
}
