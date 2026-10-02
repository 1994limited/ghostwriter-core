<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;

/**
 * A fix that writes was refused before any model was asked: a fact to add
 * is the editor's alone, and an image whose library allows no model input
 * can't be described by one.
 */
final class GapRefused extends Refused
{
    public static function fact(): self
    {
        return new self('Ghostwriter never fills in a fact. Type it in, or have the sentence written around it.');
    }

    public static function image(): self
    {
        return new self('This image\'s library doesn\'t allow Ghostwriter to look at it. Describe it yourself.');
    }

    public function status(): int
    {
        return 422;
    }
}
