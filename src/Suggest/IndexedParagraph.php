<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * A paragraph of another entry, as EntryIndex keeps it for Overlaps: its
 * entry, the entry's title and address, and the paragraph's shingles.
 */
final class IndexedParagraph
{
    /**
     * @param  list<int>  $shingles  Shingles::of() of the paragraph.
     */
    public function __construct(
        public readonly EntryRef $entry,
        public readonly string $title,
        public readonly array $shingles,
        public readonly ?string $url = null,
    ) {}
}
