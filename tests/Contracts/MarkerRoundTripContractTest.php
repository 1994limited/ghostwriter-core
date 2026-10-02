<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run MarkerRoundTripContract through an addon's own apply
 * path.
 */
abstract class MarkerRoundTripContractTest extends TestCase
{
    use MarkerRoundTripContract;
}
