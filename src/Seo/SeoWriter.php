<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * Writes an SEO title or description into an entry's values, in the shape
 * the addon's SEO field keeps it (SEO layer §9.3): SEO Pro's custom string,
 * SEOmatic's value with its source and its override switch on, a plain
 * field's text. The port beside Gaps\SeoFields, which reads them back.
 *
 * It only writes: whether a value may be written at all is MetaPolicy's
 * call, made first (SearchFields::apply()). Nothing is saved: the values
 * go into the form or the provisional draft, as everything else
 * Ghostwriter applies, until the editor saves.
 */
interface SeoWriter
{
    /**
     * The entry's values with $text as this SEO field's own (custom)
     * value, every other value left as it was.
     *
     * @param  array<string, mixed>  $values  The entry's values, as the addon's form or draft holds them.
     * @param  SeoField  $field  One of the fields the addon's SeoFields found for these values.
     * @return array<string, mixed>
     */
    public function write(array $values, SeoField $field, string $text): array;
}
