<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * SEO values in plain top-level text fields, by handle: `seo_title` or
 * `meta_title`, `seo_description` or `meta_description` (with or without
 * the underscore, any case). The limit is the field's own `maxLength`
 * (`meta.maxLength` or `meta.character_limit`) or 60 and 160. A toggle
 * named `noindex`, `no_index` or `seo_noindex` says whether the page asks
 * not to be indexed. Plain fields add no site name to the title. Each
 * addon uses it beside its SEO addons' readers, and Filament alone.
 */
final class PlainSeoFields implements SeoFields
{
    private const ROLES = [
        SeoField::TITLE => '/^(?:seo|meta)_?title$/i',
        SeoField::DESCRIPTION => '/^(?:seo|meta)_?description$/i',
    ];

    private const NOINDEX = '/^(?:seo_?|meta_?)?no_?index$/i';

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

    public function noindex(Schema $schema, EntryData $entry): ?bool
    {
        foreach ($schema->fields as $field) {
            if ($field->kind === Kind::Toggle && preg_match(self::NOINDEX, $field->handle) === 1) {
                return self::truthy($entry->get($field->handle));
            }
        }

        return null;
    }

    public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat // @phpstan-ignore return.unusedType (SeoFields::titleFormat(), declared with @method until the addons implement it)
    {
        return null;
    }

    /** A toggle's stored value: true, 1, '1', 'true', 'on', 'yes'. */
    public static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }
}
