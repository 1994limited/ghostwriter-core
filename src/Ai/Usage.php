<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * Tokens a call was billed for. Input includes cached input; output includes
 * any thinking the provider bills as output.
 */
final class Usage
{
    public function __construct(
        public readonly int $input = 0,
        public readonly int $output = 0,
    ) {}

    public function plus(Usage $other): self
    {
        return new self($this->input + $other->input, $this->output + $other->output);
    }
}
