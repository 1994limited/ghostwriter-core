<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * The entries a link can point at, for broken links and for "Link to
 * /contact": a match of the hint against titles and slugs, never a model.
 * Statamic and Craft have one; Filament has no link fields core knows, so
 * it has none.
 */
interface LinkTargets
{
    /**
     * Whether what a link holds points at something that exists: a link
     * field's value (`entry::abc`, a Hyper link list), or an inline link's
     * address. False only for a link to an entry or asset that has gone;
     * null when it can't tell (an outside address, an empty value).
     */
    public function exists(mixed $target, Field $field): ?bool;

    /**
     * Entries whose title or slug matches a hint ("contact-page"), best
     * first.
     *
     * @return list<LinkTarget>
     */
    public function search(string $hint, int $limit = 3): array;
}
