<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * The site's other entries, as the free checks and the review call need
 * them: paragraphs to compare for Overlaps, and the entries nearest a page
 * for the review's digest. Each addon implements it over what it keeps
 * (Statamic: titles, slugs and shingles beside the revisit shards; Craft:
 * the revisit table; Filament: records of the same resource).
 *
 * Neither method calls a model. Both are for one site, the entry's own.
 */
interface EntryIndex
{
    /**
     * Paragraphs of other entries that share at least one shingle with
     * these, most shared first, at most $limit. The entry itself is left
     * out.
     *
     * @param  list<int>  $shingles  Shingles::of() of one paragraph.
     * @return list<IndexedParagraph>
     */
    public function sharing(array $shingles, EntryRef $except, int $limit = 5): array;

    /**
     * Entries nearest to a page, for the review's digest: the same group
     * first, by how much their titles and summaries share words with
     * $text. The entry itself is left out.
     *
     * @return list<DigestEntry>
     */
    public function nearest(EntryRef $entry, string $text, int $limit = 20): array;
}
