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

/**
 * Everything the detectors read: the entry's current values (the form's,
 * in EntryData's shape, or the saved entry's), its schema, the addon's
 * dialects and ports, and what is known besides.
 *
 * - The ports are optional: without PlaceholderAssets and AssetRefs no
 *   image is recognised as a placeholder, without LinkTargets no link is
 *   checked or suggested, and without StockImages (stock photos off) no
 *   preview is looked up.
 * - `pattern`: the group's fill rates, where the house style was learned,
 *   say which empty fields are expected to be filled.
 * - `session`: the gap list kept when a draft was applied; it only makes
 *   messages better and says which fields the draft meant to have filled.
 * - `sources`: the texts a count to check may have been counted from (the
 *   brief, the answers and the draft: `ExtraSources::fromSession()->all()`).
 *   With them, a count whose list has since changed says so; without
 *   them, a count is only checked against its own list.
 * - `alt` and `seo` (Suggest edits): an asset's alt text and the entry's
 *   SEO values, for MissingAlt and SeoLength. Without them, neither finds
 *   anything.
 *
 * Use named arguments: the order may grow.
 */
final class GapContext
{
    /**
     * @param  array<int, string>  $sources
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
    ) {}

    /** How often entries of this group fill this place, 0 when unknown. */
    public function fillRate(string $rateKey): float
    {
        return $rateKey === '' ? 0.0 : (float) ($this->pattern->filled[$rateKey] ?? 0);
    }
}
