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
 *
 * noindex() and titleFormat() are declared here for the type checker
 * only until every addon's main implements them (an addon still on the
 * old interface would otherwise fail to load); they become interface
 * methods again then. Call them only on an implementation that has them
 * (every implementation in this repo does).
 *
 * @method ?bool noindex(Schema $schema, EntryData $entry) Whether the page asks search engines not to index it, from the setting (never the rendered tag): true or false where an addon or a field says, null where nothing does.
 * @method ?TitleFormat titleFormat(Schema $schema, EntryData $entry) How the page's `<title>` adds the site name to its SEO title; null where nothing adds one.
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
}
