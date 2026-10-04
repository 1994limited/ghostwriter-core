<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;

/**
 * An asset's alt text, as the CMS keeps it on the asset: Statamic's
 * container blueprint `alt` field, Craft 5's native `Asset::$alt`.
 * Filament keeps alt text in the form (`->ghostwriterAlt()`), so it has
 * none and reads that field as a form value instead.
 */
interface AssetAlt
{
    /**
     * The asset's alt text: '' when it's empty, null when its container or
     * volume has no alt field (nothing to ask for) or the asset is gone.
     */
    public function altFor(AssetRef $asset): ?string;
}
