<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * Where an entry's SEO title and description are, per SEO addon (SEO Pro,
 * Aardvark, SEOmatic, SEO by ether) or plain fields (PlainSeoFields). Each
 * addon implements it; without one, nothing is checked.
 */
interface SeoFields
{
    /**
     * @return list<SeoField>
     */
    public function in(Schema $schema, EntryData $entry): array;
}
