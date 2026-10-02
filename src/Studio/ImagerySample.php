<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;

/**
 * One existing image the imagery analyst looks at, with the asset and file
 * name it came from where the addon knows them, for the model-input guard.
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
        public readonly ?AssetRef $asset = null,
        public readonly ?string $filename = null,
    ) {}
}
