<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run SeoWriterContract against an addon's own implementation.
 */
abstract class SeoWriterContractTest extends TestCase
{
    use SeoWriterContract;
}
