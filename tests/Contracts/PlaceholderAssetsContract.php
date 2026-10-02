<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * What an addon's PlaceholderAssets must do: the placeholder its own
 * AssetSink makes is recognised once read back through its AssetRefs, and
 * an ordinary image given the placeholder's title is not.
 */
trait PlaceholderAssetsContract
{
    abstract protected function placeholderSink(): AssetSink;

    abstract protected function placeholderAssets(): PlaceholderAssets;

    abstract protected function placeholderAssetRefs(): AssetRefs;

    /** An image field of the addon's, where the sink can save. */
    abstract protected function placeholderImageField(): Field;

    /**
     * The field's value holding an ordinary image (not the placeholder)
     * titled Placeholders::TITLE, saved where the placeholder would be.
     */
    abstract protected function ordinaryImageTitledLikeThePlaceholder(Field $field): mixed;

    public function test_the_placeholder_the_sink_makes_is_recognised(): void
    {
        $field = $this->placeholderImageField();
        $sink = $this->placeholderSink();
        $reference = $sink->placeholder($field, fn () => Placeholders::available() ? Placeholders::png() : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $this->assertNotNull($reference, 'The sink could not save the placeholder.');

        $assets = $this->placeholderAssetRefs()->in($sink->value($field, $reference), $field);

        $this->assertCount(1, $assets);
        $this->assertTrue($this->placeholderAssets()->isPlaceholder($assets[0], $field));
    }

    public function test_an_ordinary_image_with_the_placeholders_title_is_not_one(): void
    {
        $field = $this->placeholderImageField();
        $assets = $this->placeholderAssetRefs()->in($this->ordinaryImageTitledLikeThePlaceholder($field), $field);

        $this->assertNotEmpty($assets);

        foreach ($assets as $asset) {
            $this->assertFalse($this->placeholderAssets()->isPlaceholder($asset, $field));
        }
    }
}
