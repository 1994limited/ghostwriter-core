<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * Finding the page a link already in a draft points at, in the link index
 * (SEO layer §7.5, decision 22): a writer that wrote `statamic://entry::abc`
 * or `/journal/october` for a real page of the site keeps its link
 * (Seo\LinkGuard), where any other address becomes a `#gw-link:` marker.
 *
 * Each addon implements it on its entry index beside LinkIndex, by handing
 * the site's rows to Seo\LinkCandidates::rowFor(), so the three CMSes match
 * alike. It is its own port, so an addon without it still loads with this
 * core: its writer links are then all markers, as before.
 */
interface LinkLookup
{
    /**
     * The site's row (either scope) a link points at, matched by
     * Seo\LinkCandidates::linkKey(): a reference (`statamic://entry::abc`,
     * `entry::abc`, `{entry:12@1:url||…}`) to the row's link, or an address
     * to the row's. Null when no row of that site matches. Whether the page
     * may be linked to is the caller's to ask (Seo\Linkable).
     *
     * @param  int|string|null  $site  A Statamic handle, a Craft site ID, a Filament tenant.
     */
    public function linkRow(string $href, int|string|null $site = null): ?IndexRow;
}
