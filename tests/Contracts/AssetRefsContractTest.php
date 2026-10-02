<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run AssetRefsContract against an addon's own AssetRefs.
 */
abstract class AssetRefsContractTest extends TestCase
{
    use AssetRefsContract;
}
