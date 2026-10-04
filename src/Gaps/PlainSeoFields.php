<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * SEO values in plain top-level text fields, by handle: `seo_title` or
 * `meta_title`, `seo_description` or `meta_description` (with or without
 * the underscore, any case). The limit is the field's own `maxLength`
 * (`meta.maxLength` or `meta.character_limit`) or 60 and 160. Each addon
 * uses it beside its SEO addons' readers, and Filament alone.
 */
final class PlainSeoFields implements SeoFields
{
    private const ROLES = [
        SeoField::TITLE => '/^(?:seo|meta)_?title$/i',
        SeoField::DESCRIPTION => '/^(?:seo|meta)_?description$/i',
    ];

    public function in(Schema $schema, EntryData $entry): array
    {
        $found = [];

        foreach ($schema->fields as $field) {
            if (! in_array($field->kind, [Kind::Text, Kind::LongText], true)) {
                continue;
            }

            foreach (self::ROLES as $role => $pattern) {
                if (preg_match($pattern, $field->handle) !== 1) {
                    continue;
                }

                $value = $entry->get($field->handle);
                $limit = $field->meta['maxLength'] ?? $field->meta['character_limit'] ?? null;
                $found[] = new SeoField(
                    FieldPath::of($field->handle),
                    $role,
                    $field->label !== '' ? $field->label : $field->handle,
                    is_numeric($limit) && (int) $limit > 0 ? (int) $limit : SeoField::LIMITS[$role],
                    is_scalar($value) ? (string) $value : '',
                );
            }
        }

        return $found;
    }
}
