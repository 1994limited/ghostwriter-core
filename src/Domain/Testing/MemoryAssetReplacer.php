<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetReplacer;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ReplaceMeta;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use RuntimeException;

/**
 * An AssetReplacer that keeps each file it is given, for tests. It can be
 * told to fail the next replacements (`failing`), or to say the asset
 * moved (`movesTo`).
 */
final class MemoryAssetReplacer implements AssetReplacer
{
    /** @var array<int, array{asset: AssetRef, file: PhotoFile, meta: ReplaceMeta}> */
    public array $replaced = [];

    public int $failing = 0;

    public ?AssetRef $movesTo = null;

    public function replace(AssetRef $asset, PhotoFile $file, ReplaceMeta $meta): AssetRef
    {
        if ($this->failing > 0) {
            $this->failing--;

            throw new RuntimeException('The disk is full.');
        }

        $this->replaced[] = ['asset' => $asset, 'file' => $file, 'meta' => $meta];

        return $this->movesTo ?? $asset;
    }
}
