<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run LinkIndexContract against an addon's own implementation.
 */
abstract class LinkIndexContractTest extends TestCase
{
    use LinkIndexContract;
}
