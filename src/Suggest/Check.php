<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * One free check: deterministic, no model, no network.
 */
interface Check
{
    /**
     * The finding kinds it gives.
     *
     * @return list<string>
     */
    public function kinds(): array;

    /**
     * @return iterable<Finding>
     */
    public function find(CheckContext $context): iterable;
}
