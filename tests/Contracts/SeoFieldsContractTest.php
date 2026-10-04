<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run SeoFieldsContract against an addon's own implementation.
 */
abstract class SeoFieldsContractTest extends TestCase
{
    use SeoFieldsContract;
}
