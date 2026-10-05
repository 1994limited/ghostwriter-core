<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;

/**
 * Everything the detectors read: the entry's current values (the form's,
 * in EntryData's shape, or the saved entry's), its schema, the addon's
 * dialects and ports, and what is known besides.
 *
 * - The ports are optional: without PlaceholderAssets and AssetRefs no
 *   image is recognised as a placeholder, without LinkTargets no link is
 *   checked or suggested, and without StockImages (stock photos off) no
 *   preview is looked up.
 * - `pattern`: the group's fill rates (FillRates over its newest
 *   published entries, or the learned house style's) say which empty
 *   fields are expected to be filled; its `entries`, how many entries
 *   they were counted over (0 when unknown).
 * - `group`: what editors call the group ("Journal"), for "Most Journal
 *   entries have one"; '' when unknown.
 * - `profile`: the group's render profile (Seo\RenderProfile), when a
 *   render has been seen: a block type that prints the page's `h1` is the
 *   hero, so an image in it is the page's prominent one.
 * - `session`: the gap list kept when a draft was applied; it only makes
 *   messages better and says which fields the draft meant to have filled.
 * - `sources`: the texts a count to check may have been counted from (the
 *   brief, the answers and the draft: `ExtraSources::fromSession()->all()`).
 *   With them, a count whose list has since changed says so; without
 *   them, a count is only checked against its own list.
 * - `alt` and `seo` (Suggest edits): an asset's alt text and the entry's
 *   SEO values, for MissingAlt and SeoLength. Without them, neither finds
 *   anything.
 * - `hosts`: the site's own hosts ("northfold.co.uk"), so FewLinks counts
 *   a full address on the site as a link to it. Links the CMS stores as
 *   references (`statamic://entry::abc`, `{entry:12@1:url}`) and paths
 *   (`/contact`) count without them.
 *
 * Use named arguments: the order may grow.
 */
final class GapContext
{
    /**
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $hosts
     */
    public function __construct(
        public readonly Schema $schema,
        public readonly EntryData $entry,
        public readonly RichTextDialect $richText = new HtmlDialect,
        public readonly LinkDialect $links = new NoLinks,
        public readonly ?PlaceholderAssets $placeholders = null,
        public readonly ?AssetRefs $assets = null,
        public readonly ?LinkTargets $targets = null,
        public readonly ?StockImages $stock = null,
        public readonly ?Pattern $pattern = null,
        public readonly SessionGaps $session = new SessionGaps,
        public readonly array $sources = [],
        public readonly ?AssetAlt $alt = null,
        public readonly ?SeoFields $seo = null,
        public readonly string $group = '',
        public readonly ?RenderProfile $profile = null,
        public readonly array $hosts = [],
    ) {}

    /** The fewest words, outside the title, that make an entry more than untouched. */
    public const ENGAGED_WORDS = 12;

    /** Top-level fields that don't count as content: what a new entry is given first. */
    private const NOT_CONTENT = ['title', 'name', 'slug'];

    private ?bool $engaged = null;

    /**
     * Whether the entry is more than a new, untouched one: a draft from
     * Ghostwriter was applied (the session kept its gap list), or it holds
     * at least ENGAGED_WORDS words outside its title. Until then, nothing
     * that only prompts (an image to add) is raised: the CMS's form for a
     * new entry isn't nagged.
     */
    public function engaged(): bool
    {
        if ($this->engaged !== null) {
            return $this->engaged;
        }

        if (! $this->session->isEmpty()) {
            return $this->engaged = true;
        }

        $words = 0;

        foreach (Walk::entry($this->schema, $this->entry) as $visit) {
            if (count($visit->path->segments) === 1 && in_array($visit->path->handle(), self::NOT_CONTENT, true)) {
                continue;
            }

            $text = Walk::text($visit, $this->richText);
            $words += $text === null ? 0 : (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);

            if ($words >= self::ENGAGED_WORDS) {
                return $this->engaged = true;
            }
        }

        return $this->engaged = false;
    }

    /** How many entries the fill rates were counted over; 0 when unknown. */
    public function siblings(): int
    {
        return $this->pattern->entries ?? 0;
    }

    /** How often entries of this group fill this place, 0 when unknown. */
    public function fillRate(string $rateKey): float
    {
        return $rateKey === '' ? 0.0 : (float) ($this->pattern->filled[$rateKey] ?? 0);
    }
}
