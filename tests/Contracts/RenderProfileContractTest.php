<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run RenderProfileContract against an addon's profile store and outline endpoint.
 */
abstract class RenderProfileContractTest extends TestCase
{
    use RenderProfileContract;
}
