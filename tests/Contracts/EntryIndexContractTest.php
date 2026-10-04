<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run EntryIndexContract against an addon's own implementation.
 */
abstract class EntryIndexContractTest extends TestCase
{
    use EntryIndexContract;
}
