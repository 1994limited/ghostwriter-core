<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;

/**
 * An image already in the place a photo is for, with the asset and file
 * name it came from, so the model-input guard can check its ledger record
 * and name as well as its bytes. Plain bytes or an Image still work as
 * references; this is for when the addon knows where they came from.
 */
final class ReferenceImage
{
    public function __construct(
        public readonly Image|string $image,
        public readonly ?AssetRef $asset = null,
        public readonly ?string $filename = null,
    ) {}

    public function bytes(): string
    {
        return $this->image instanceof Image ? $this->image->data : $this->image;
    }
}
