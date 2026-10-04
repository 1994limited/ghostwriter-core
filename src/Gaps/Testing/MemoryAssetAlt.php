<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;

/**
 * Alt text from a list, by AssetRef::key(), for tests and the demo. An
 * asset in a volume listed in `$withoutAlt` has no alt field (null); any
 * other asset not listed has empty alt text.
 */
final class MemoryAssetAlt implements AssetAlt
{
    /**
     * @param  array<string, string>  $alts  By AssetRef::key().
     * @param  array<int, string>  $withoutAlt  Volumes with no alt field.
     */
    public function __construct(
        public array $alts = [],
        private readonly array $withoutAlt = [],
    ) {}

    public function altFor(AssetRef $asset): ?string
    {
        if (in_array($asset->volume, $this->withoutAlt, true)) {
            return null;
        }

        return $this->alts[$asset->key()] ?? '';
    }
}
