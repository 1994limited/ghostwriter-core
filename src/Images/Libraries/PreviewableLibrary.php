<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * A library with comps: watermarked previews of photos not yet licensed.
 */
interface PreviewableLibrary extends PhotoLibrary
{
    /**
     * The comp, for the control panel only, never saved as a CMS asset.
     * Kept privately until its keepUntil (Capabilities::$previewKeepDays),
     * or, where the terms allow no storage, only the provider's address.
     * Each call asks the provider afresh: a comp's address is never kept
     * for later.
     *
     * @throws PhotoUnavailable
     */
    public function preview(string $id): Preview;
}
