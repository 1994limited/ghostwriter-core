<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview\RoundTrip;

use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PreviewMarkerContractTest;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Preview\Support\BardHtml;

/** Bard JSON (Statamic), stored by core's copy of its dialect and printed node by node. */
final class BardPreviewMarkerTest extends PreviewMarkerContractTest
{
    use RendersCoreShapes;

    protected function dialect(): RichTextDialect
    {
        return new BardDialect(newId: fn () => 'set1');
    }

    protected function previewField(string $shape): Field
    {
        return $shape === 'rich' ? new Field('copy', Kind::RichText, 'Copy', type: 'bard') : parent::previewField($shape);
    }

    protected function renderRich(mixed $stored): string
    {
        $this->assertIsArray($stored, 'Bard stores nodes');

        return BardHtml::render($stored);
    }
}
