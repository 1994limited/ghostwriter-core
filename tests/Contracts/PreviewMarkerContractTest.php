<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run PreviewMarkerContract through an addon's own apply
 * path and rendering.
 */
abstract class PreviewMarkerContractTest extends TestCase
{
    use PreviewMarkerContract;
}
