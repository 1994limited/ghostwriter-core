<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\MemoryAssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\AssetRefsContract;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PlaceholderAssetsContract;
use PHPUnit\Framework\TestCase;

/** The asset contracts, run against core's in-memory ports. */
final class MemoryAssetsContractsTest extends TestCase
{
    use AssetRefsContract;
    use PlaceholderAssetsContract;

    private ?MemoryAssetSink $sink = null;

    protected function assetRefs(): AssetRefs
    {
        return new MemoryAssets;
    }

    protected function assetInAField(): array
    {
        return [new Field('image', Kind::Reference, files: true, meta: ['images' => true]), ['photos::rocks.jpg'], new AssetRef('photos', 'rocks.jpg')];
    }

    protected function assetInlineInRichText(): array
    {
        return [new Field('body', Kind::RichText), 'Rocks at dusk. ![Rocks](asset::photos::rocks.jpg) Lovely.', new AssetRef('photos', 'rocks.jpg')];
    }

    protected function placeholderSink(): AssetSink
    {
        return $this->sink ??= new MemoryAssetSink;
    }

    protected function placeholderAssets(): PlaceholderAssets
    {
        return new MemoryAssets;
    }

    protected function placeholderAssetRefs(): AssetRefs
    {
        return new MemoryAssets;
    }

    protected function placeholderImageField(): Field
    {
        return new Field('image', Kind::Reference, files: true, meta: ['images' => true, 'container' => 'assets']);
    }

    protected function ordinaryImageTitledLikeThePlaceholder(Field $field): mixed
    {
        return ['assets::ghostwriter/image-to-choose.png'];
    }
}
