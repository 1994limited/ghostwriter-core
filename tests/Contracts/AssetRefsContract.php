<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * What an addon's AssetRefs must do: find the asset in an image field, and
 * an image inline in rich text, as the same asset the stock ledger and the
 * placeholder check know it by (AssetRef::is()).
 */
trait AssetRefsContract
{
    abstract protected function assetRefs(): AssetRefs;

    /**
     * An image field of the addon's, its value holding one asset, and that
     * asset.
     *
     * @return array{Field, mixed, AssetRef}
     */
    abstract protected function assetInAField(): array;

    /**
     * A rich text field of the addon's, its value holding one image inline
     * among some words, and that asset.
     *
     * @return array{Field, mixed, AssetRef}
     */
    abstract protected function assetInlineInRichText(): array;

    public function test_the_asset_in_an_image_field_is_found(): void
    {
        [$field, $value, $asset] = $this->assetInAField();
        $found = $this->assetRefs()->in($value, $field);

        $this->assertCount(1, $found);
        $this->assertTrue($asset->is($found[0]), $found[0]->key().' is not '.$asset->key());
    }

    public function test_an_image_inline_in_rich_text_is_found(): void
    {
        [$field, $value, $asset] = $this->assetInlineInRichText();
        $found = $this->assetRefs()->in($value, $field);

        $this->assertCount(1, $found);
        $this->assertTrue($asset->is($found[0]), $found[0]->key().' is not '.$asset->key());
    }

    public function test_an_empty_value_has_no_assets(): void
    {
        [$field] = $this->assetInAField();
        [$richText] = $this->assetInlineInRichText();

        foreach ([null, '', []] as $empty) {
            $this->assertSame([], $this->assetRefs()->in($empty, $field));
            $this->assertSame([], $this->assetRefs()->in($empty, $richText));
        }
    }
}
