<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run EntrySourceContract against an addon's own implementation.
 */
abstract class EntrySourceContractTest extends TestCase
{
    use EntrySourceContract;
}
