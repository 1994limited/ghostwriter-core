<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetAltContractTest;

/** Core's own AssetAlt for tests passes the contract the addons run. */
final class MemoryAssetAltContractTest extends AssetAltContractTest
{
    protected function assetAlt(): AssetAlt
    {
        return new MemoryAssetAlt(['photos::path.jpg' => 'A gravel path between box hedges'], ['uploads']);
    }

    protected function assetWithAlt(): AssetRef
    {
        return new AssetRef('photos', 'path.jpg');
    }

    protected function assetWithoutAlt(): AssetRef
    {
        return new AssetRef('photos', 'materials.jpg');
    }

    protected function assetWithNoAltField(): AssetRef
    {
        return new AssetRef('uploads', 'logo.png');
    }
}
