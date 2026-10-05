<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;

/**
 * What the SEO pass needs for a draft's search title, description and
 * address (SEO layer §9, §10), given by the addon on LayoutContext::$meta:
 *
 * - `fields`: the addon's SeoFields, with `schema` (the entry's whole
 *   blueprint, its SEO field included) and `entry` (the values they are
 *   read from: the entry being edited, or a new entry's defaults; with its
 *   group and site, whose SEO defaults apply);
 * - `newEntry`: whether the entry is new, or was never published;
 * - `provenance`: the SEO text Ghostwriter wrote into this entry before
 *   (its earlier sessions'), so its own text isn't taken for a person's;
 * - `slug`: where the address goes (null: no slug is set);
 * - `kind`, `voice`, `locale`: what the `seo-editor` call is told, where
 *   there is no LinkContext to say it.
 *
 * Without one, a draft gets no search title or description, and no slug.
 */
final class MetaContext
{
    public function __construct(
        public readonly SeoFields $fields,
        public readonly Schema $schema,
        public readonly EntryData $entry,
        public readonly bool $newEntry = true,
        public readonly SeoProvenance $provenance = new SeoProvenance,
        public readonly ?SlugContext $slug = null,
        public readonly ?ContentKind $kind = null,
        public readonly string $voice = '',
        public readonly string $locale = 'en',
    ) {}
}
