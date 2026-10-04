<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Where an SEO value the page prints comes from, as the SEO addon resolves
 * it: the entry, its section's defaults, then the site's.
 */
enum SeoSource: string
{
    /** The entry's own text, typed for this page. */
    case Custom = 'custom';

    /** Another field's text (SEO Pro `@seo:excerpt`, SEOmatic `fromField`): SeoField::$inheritsFrom names it. */
    case Field = 'field';

    /** Fixed text set once for the section or the site, the same on every page that inherits it. */
    case Default = 'default';

    /** A template core can't evaluate (Antlers, Twig): there is text, but not text to check. */
    case Template = 'template';

    /** Switched off: the page prints none (SEO Pro `false`, SEOmatic `none`). */
    case Disabled = 'disabled';

    /** Whether the text comes from somewhere other than the entry's own value. */
    public function inherited(): bool
    {
        return in_array($this, [self::Field, self::Default], true);
    }
}
