<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;

/**
 * One existing image the imagery analyst looks at.
 */
final class ImagerySample
{
    /**
     * @param  string  $label  The field it is in.
     * @param  string  $on  The title of the entry it is on.
     */
    public function __construct(
        public readonly string $label,
        public readonly string $on,
        public readonly Image $image,
    ) {}
}
