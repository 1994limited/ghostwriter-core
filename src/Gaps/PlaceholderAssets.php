<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Recognises Ghostwriter's striped placeholder (Images\Placeholders), the
 * marker for an image still to choose, by the addon's own rule:
 *
 * - Statamic: its path, `ghostwriter/image-placeholder.png`;
 * - Craft: the file name Placeholders::FILENAME, in any volume;
 * - Filament: `StorageAssetSink::isPlaceholder()`.
 *
 * Never by title: an ordinary asset titled like the placeholder is not one.
 */
interface PlaceholderAssets
{
    public function isPlaceholder(AssetRef $asset, Field $field): bool;
}
