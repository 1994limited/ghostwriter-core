<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;

/**
 * What the SEO pass needs to link a draft to the site's other pages (SEO
 * layer §7): the addon's link index and how its rich text stores a link,
 * where the page is going (its group, site and, when it exists, the page
 * itself), and what the model is told about it (its kind, the voice guide,
 * its language). An addon that gives LayoutContext one gets internal links
 * on every first draft; without one, drafts get none.
 *
 *     new LayoutContext($schema, …, links: new LinkContext($index, new StatamicLinks, 'journal', 'default', null, $kind, $voice, 'en_GB'));
 */
final class LinkContext
{
    /**
     * @param  string  $group  The collection, section or resource the page is in (LinkIndex::related()).
     * @param  int|string|null  $site  Its site: a Statamic handle, a Craft site ID, a Filament tenant.
     * @param  EntryRef|null  $except  The page itself, when it exists (Edit with Ghostwriter).
     * @param  string  $voice  The voice guide; '' when none is written.
     * @param  string  $locale  The page's language ("en_GB").
     */
    public function __construct(
        public readonly LinkIndex $index,
        public readonly InlineLinks $links,
        public readonly string $group,
        public readonly int|string|null $site = null,
        public readonly ?EntryRef $except = null,
        public readonly ?ContentKind $kind = null,
        public readonly string $voice = '',
        public readonly string $locale = 'en',
    ) {}
}
