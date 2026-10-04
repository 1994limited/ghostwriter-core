<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * A LinkDialect that can write an inline link to another page of the site
 * into a draft's Markdown, as the field's rich text stores it (SEO layer
 * §7.4): the SEO pass links words to the pages the link index offers.
 * Core never builds a CMS reference itself; the dialect does.
 *
 * - StatamicLinks: `statamic://entry::abc`, which Bard stores and Statamic
 *   resolves to the page's address when it renders, so a later slug
 *   change doesn't break it. A term: its address.
 * - CraftLinks: `{entry:12@1:url||/journal/winter-care}`, the reference
 *   CKEditor stores, with the address as its fallback.
 * - FilamentLinks: the public address `->publicUrlUsing()` gives.
 * - NoLinks: none.
 *
 * It is a separate interface, like LinkPlaceholders, so a LinkDialect
 * written outside core keeps working.
 */
interface InlineLinks
{
    /**
     * The href an inline link to this page carries in a draft's Markdown,
     * or null when it can't be linked to inline (the link is then not
     * made).
     */
    public function inlineHref(DigestEntry $entry): ?string;
}
