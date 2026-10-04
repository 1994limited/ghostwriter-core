<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * The link targets of a site: the published pages a new or edited page
 * could link to, from both kinds of index row (IndexScope): Ghostwriter's
 * own groups and every other routable group. Each addon implements it on
 * its entry index (Statamic FileEntryIndex, Craft DbEntryIndex, Filament
 * EloquentEntryIndex) by reading the site's rows and handing them to
 * Seo\LinkCandidates::rank(), so the three CMSes rank alike.
 *
 * This is the SEO layer's `EntryIndex::related()` (§7.1), kept as its own
 * port so an addon that doesn't have it yet still loads with this core.
 * No model.
 */
interface LinkIndex
{
    /**
     * Published pages of the site that could be linked from a page about
     * $text, best first, at most $limit (LinkCandidates::LIMIT, 25): the
     * draft's title, headings and first 200 words are matched against each
     * row's title, slug and summary (LinkCandidates::score()). Never another
     * site's pages, the page itself ($except), pages $text already links to
     * ($linked: hrefs as the draft stores them), or pages Seo\Linkable
     * leaves out on $now (drafts, scheduled before their day, expired, no
     * address, noindex, utility pages, the home page).
     *
     * For a page that doesn't exist yet, $except is null and $group is the
     * group it will be in.
     *
     * @param  string  $text  The draft: its title on the first line, then the body (Markdown or plain).
     * @param  int|string|null  $site  The draft's site: a Statamic handle, a Craft site ID, a Filament tenant.
     * @param  list<string>  $linked  Hrefs already in the draft ('statamic://entry::abc', '{entry:12@1:url||…}', '/contact').
     * @return list<DigestEntry>
     */
    public function related(string $text, string $group, int|string|null $site = null, ?EntryRef $except = null, int $limit = 25, array $linked = [], ?DateTimeImmutable $now = null): array;
}
