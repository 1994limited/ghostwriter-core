<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * Where a draft's address goes (SEO layer §10, decision 13), as the addon
 * knows it: whether Ghostwriter may set the entry's slug at all (a new
 * entry, or one never published, whose slug is empty or still the one the
 * CMS made from its title), whether its group is dated (a journal: a year
 * may stay in the address), the slugs already taken in its scope, the
 * address it has now, and what comes before the slug in its address
 * ("northfold.garden/garden-services/"), for the Search section.
 */
final class SlugContext
{
    /**
     * @param  list<string>  $taken
     */
    public function __construct(
        public readonly bool $settable,
        public readonly bool $dated = false,
        public readonly array $taken = [],
        public readonly ?string $current = null,
        public readonly string $base = '',
    ) {}
}
