<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use PHPUnit\Framework\TestCase;

/**
 * Extend this to run PublishGuardContract through an addon's own save
 * hooks.
 */
abstract class PublishGuardContractTest extends TestCase
{
    use PublishGuardContract;
}
