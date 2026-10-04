<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Where an entry's SEO title and description are, per SEO addon (SEO Pro,
 * Aardvark, SEOmatic, SEO by ether) or plain fields (PlainSeoFields), and
 * what the addon makes of them: the text the page prints, resolved as the
 * addon does (the entry, then its section's defaults, then the site's),
 * whether the page asks not to be indexed, and how the `<title>` adds the
 * site name. Each addon implements it; without one, nothing is checked.
 *
 * EntryData::$group and ::$site say which section's and site's defaults
 * apply; without them, an addon reads the entry's own values and the
 * default site's.
 */
interface SeoFields
{
    /**
     * Every SEO title and description the entry has, switched-off ones
     * included (SeoSource::Disabled, with no text).
     *
     * @return list<SeoField>
     */
    public function in(Schema $schema, EntryData $entry): array;

    /**
     * Whether the page asks search engines not to index it, from the
     * setting (never the rendered tag, which some addons change outside
     * production): true or false where an addon or a field says, null
     * where nothing does.
     */
    public function noindex(Schema $schema, EntryData $entry): ?bool;

    /**
     * How the page's `<title>` adds the site name to its SEO title; null
     * where nothing adds one (plain fields, no SEO addon).
     */
    public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat;
}
