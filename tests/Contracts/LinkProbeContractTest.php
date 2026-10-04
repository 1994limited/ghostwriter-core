<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run LinkProbeContract against an addon's own implementation.
 */
abstract class LinkProbeContractTest extends TestCase
{
    use LinkProbeContract;
}
