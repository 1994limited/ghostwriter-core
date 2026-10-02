<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * What a Studio job came back with, and what it cost: the tokens of every
 * call it made, retries and re-asks included.
 *
 * @template-covariant T
 */
final class Result
{
    /**
     * @param  T  $value
     */
    public function __construct(
        public readonly mixed $value,
        public readonly Usage $usage = new Usage,
    ) {}
}
