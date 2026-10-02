<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps\RoundTrip;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\MarkerRoundTripContractTest;

/** HTML rich text (Craft's CKEditor, Filament's RichEditor), through core's part of the apply path. */
final class HtmlMarkerRoundTripTest extends MarkerRoundTripContractTest
{
    use BuilderRoundTrip;

    protected function dialect(): RichTextDialect
    {
        return new HtmlDialect;
    }
}
