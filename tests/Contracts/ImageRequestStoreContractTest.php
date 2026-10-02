<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run ImageRequestStoreContract against an addon's own implementation.
 */
abstract class ImageRequestStoreContractTest extends TestCase
{
    use ImageRequestStoreContract;
}
