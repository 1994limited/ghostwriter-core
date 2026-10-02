<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run PlaceholderAssetsContract against an addon's own
 * ports.
 */
abstract class PlaceholderAssetsContractTest extends TestCase
{
    use PlaceholderAssetsContract;
}
