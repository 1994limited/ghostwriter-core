<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Links;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Which text fields can hold a link mark, the same for every CMS: rich
 * text, unless its toolbar is known to have no link button (Bard's
 * `buttons` without `anchor`), and markdown (a field whose type or `format`
 * meta says so). Plain text and textareas can't.
 *
 * @internal
 */
trait MarksLinks
{
    public function supportsLinks(Field $field): bool
    {
        if ($field->kind === Kind::RichText) {
            $buttons = $field->meta['buttons'] ?? null;

            return ! is_array($buttons) || array_intersect(['anchor', 'link'], $buttons) !== [];
        }

        return in_array($field->kind, [Kind::Text, Kind::LongText], true)
            && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown');
    }
}
