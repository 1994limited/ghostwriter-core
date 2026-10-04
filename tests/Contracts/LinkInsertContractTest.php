<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run LinkInsertContract against an addon's dialect, apply path and renderer.
 */
abstract class LinkInsertContractTest extends TestCase
{
    use LinkInsertContract;
}
