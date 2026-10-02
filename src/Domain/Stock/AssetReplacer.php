<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use RuntimeException;

/**
 * Swaps the file behind a stand-in asset for its licensed original,
 * keeping the asset's ID, every reference to it, its title, alt text and
 * focal point: Statamic `Asset::reupload()`, Craft
 * `Assets::replaceAssetFile()`, Filament `Storage::put()` over the same
 * path.
 *
 * The bytes are written exactly as given, never re-encoded: Getty's and
 * iStock's licences require the embedded copyright, name and image ID to
 * stay. If the file can't go where the stand-in is without converting it
 * (another extension, where the CMS refuses that), throw rather than
 * convert; the licence is kept and the editor can replace it by hand.
 */
interface AssetReplacer
{
    /**
     * @return AssetRef The asset as it is now (the same, unless the addon had to move it).
     *
     * @throws RuntimeException with a message an editor can read.
     */
    public function replace(AssetRef $asset, PhotoFile $file, ReplaceMeta $meta): AssetRef;
}
