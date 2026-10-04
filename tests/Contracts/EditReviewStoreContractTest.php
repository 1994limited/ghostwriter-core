<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run EditReviewStoreContract against an addon's own implementation.
 */
abstract class EditReviewStoreContractTest extends TestCase
{
    use EditReviewStoreContract;
}
