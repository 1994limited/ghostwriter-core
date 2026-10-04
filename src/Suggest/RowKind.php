<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * What an index row's page is: an entry (Statamic, Craft), a taxonomy term
 * (Statamic), a category (Craft) or a record (Filament). Terms and
 * categories are listing pages, so they rank just below a page about the
 * same subject (Seo\LinkCandidates).
 */
enum RowKind: string
{
    case Entry = 'entry';
    case Term = 'term';
    case Category = 'category';
    case Record = 'record';

    /** A term or a category: a page listing other pages. */
    public function isListing(): bool
    {
        return $this === self::Term || $this === self::Category;
    }
}
