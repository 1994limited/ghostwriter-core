<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * What SearchFields::apply() did: the entry's values with the search title
 * and description written where they may be, the hashes of what
 * Ghostwriter wrote (for SeoState::withWritten()), what it decided per
 * role, and the texts it only suggests (for Finish this page).
 */
final class SearchApplied
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, MetaAction>  $actions  By role.
     * @param  array<string, string>  $suggested  By role.
     */
    public function __construct(
        public readonly array $values,
        public readonly SeoProvenance $written,
        public readonly array $actions = [],
        public readonly array $suggested = [],
    ) {}
}
