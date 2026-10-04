<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run AssetAltContract against an addon's own implementation.
 */
abstract class AssetAltContractTest extends TestCase
{
    use AssetAltContract;
}
