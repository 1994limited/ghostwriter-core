<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run HeadingLevelsContract against an addon's reader and apply path.
 */
abstract class HeadingLevelsContractTest extends TestCase
{
    use HeadingLevelsContract;
}
