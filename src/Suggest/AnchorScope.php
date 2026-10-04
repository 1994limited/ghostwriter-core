<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/** What a finding or a suggestion points at. */
enum AnchorScope: string
{
    /** A quoted range inside a text value. */
    case Range = 'range';

    /** The whole value of a short field: an SEO description, an eyebrow, a link field. */
    case Field = 'field';

    /** An asset's alt text (Statamic, Craft), or a `ghostwriterAlt` field (Filament). */
    case Asset = 'asset';
}
