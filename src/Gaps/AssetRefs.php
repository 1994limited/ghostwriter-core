<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * The assets in a field's value, as the addon stores them: an assets or
 * upload field's references, and the images inline in rich text (Bard image
 * nodes, CKEditor `{asset:123:url}` references, Filament RichEditor
 * images). The stock ledger's usage scanners already collect these, so an
 * addon reuses them.
 */
interface AssetRefs
{
    /**
     * @return list<AssetRef> In the order they appear; empty when there are none.
     */
    public function in(mixed $value, Field $field): array;
}
