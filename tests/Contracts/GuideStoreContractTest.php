<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run GuideStoreContract against an addon's own implementation.
 */
abstract class GuideStoreContractTest extends TestCase
{
    use GuideStoreContract;
}
