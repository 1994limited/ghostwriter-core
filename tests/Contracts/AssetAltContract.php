<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetAlt;

/**
 * What every AssetAlt must do. The addon gives three assets it has made:
 * one with alt text, one without, and one in a container or volume with
 * no alt field.
 */
trait AssetAltContract
{
    abstract protected function assetAlt(): AssetAlt;

    /** An asset whose alt text is "A gravel path between box hedges". */
    abstract protected function assetWithAlt(): AssetRef;

    abstract protected function assetWithoutAlt(): AssetRef;

    /** An asset where alt text can't be kept. */
    abstract protected function assetWithNoAltField(): AssetRef;

    public function test_alt_text_is_read(): void
    {
        $this->assertSame('A gravel path between box hedges', $this->assetAlt()->altFor($this->assetWithAlt()));
    }

    public function test_empty_alt_text_is_an_empty_string(): void
    {
        $this->assertSame('', $this->assetAlt()->altFor($this->assetWithoutAlt()));
    }

    public function test_no_alt_field_is_null(): void
    {
        $this->assertNull($this->assetAlt()->altFor($this->assetWithNoAltField()));
    }
}
