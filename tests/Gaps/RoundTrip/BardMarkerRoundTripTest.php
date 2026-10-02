<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps\RoundTrip;

use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\MarkerRoundTripContractTest;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;

/** Bard nodes (Statamic), with core's copy of its dialect. */
final class BardMarkerRoundTripTest extends MarkerRoundTripContractTest
{
    use BuilderRoundTrip;

    protected function dialect(): RichTextDialect
    {
        return new BardDialect(newId: fn () => 'set1');
    }

    protected function richField(): Field
    {
        return new Field('copy', Kind::RichText, type: 'bard');
    }
}
