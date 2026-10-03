<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview\RoundTrip;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PreviewMarkerContractTest;

/** HTML rich text (Craft's CKEditor, Filament's RichEditor), printed as stored; markdown and plain text as templates print them. */
final class HtmlPreviewMarkerTest extends PreviewMarkerContractTest
{
    use RendersCoreShapes;

    protected function dialect(): RichTextDialect
    {
        return new HtmlDialect;
    }

    protected function renderRich(mixed $stored): string
    {
        return is_string($stored) ? $stored : '';
    }
}
