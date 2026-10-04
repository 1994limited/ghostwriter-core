<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * What prints a page's main heading (its `h1`), as a render profile read
 * it from a preview's outline.
 */
enum H1Source: string
{
    /** The entry's title: the usual case. */
    case Title = 'title';

    /** Another field the template prints as the `h1`, such as a hero's heading. */
    case Field = 'field';

    /** Text the template prints itself, such as a logo. */
    case Static = 'static';

    /** Nothing: the page has no `h1` of the template's own. */
    case None = 'none';

    /** More than one `h1` of the template's own. */
    case Several = 'several';

    /** Whether the template prints an `h1` itself, so a body starts below it. */
    public function printsH1(): bool
    {
        return $this !== self::None;
    }
}
