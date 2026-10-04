<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * Which kind of row an entry index holds for a page (SEO layer §7.1):
 *
 * - Full: a page of one of Ghostwriter's own groups, with its paragraphs'
 *   shingles and a Content to revisit row. sharing() (Overlaps), nearest()
 *   (the review's digest) and related() read it.
 * - Link: any other routable page of the site (a Contact page, a product
 *   category), kept only as a link target: title, address, summary, type
 *   and stems. Only related() reads it.
 */
enum IndexScope: string
{
    case Full = 'full';
    case Link = 'link';
}
