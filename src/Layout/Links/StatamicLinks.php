<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Links;

use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Statamic's links: a `link` field holds a URL or `entry::id` string, an
 * `entries` field a list of entry IDs. A link to the entry itself is
 * `entry::id`, or `[id]` in an entries field; its title can sit in any
 * plain value beside it (a crumb's label).
 *
 * Only a `link` field can be pointed at example.com, with any text field
 * named for it (`link_text`, `button_text` for `button_link`) saying "Link
 * to choose". Entries to pick can't be stood in for.
 */
final class StatamicLinks implements LinkDialect
{
    /** Fieldtypes that hold where a link goes. */
    private const TYPES = ['link', 'entries'];

    public function holdsLinks(Field $field): bool
    {
        return in_array($field->type, self::TYPES, true);
    }

    public function hasLink(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($item) => $this->hasLink($item)) !== [];
        }

        return is_string($value) && trim($value) !== '';
    }

    public function looksLikeLink(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('#^(entry::|asset::|term::|https?://|mailto:|tel:)#', $value);
    }

    public function generalise(array $values, int|string $id, string $title): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[$key] = $this->general($value, (string) $id, $title);
        }

        return $out;
    }

    public function toSelf(int|string $id): string
    {
        return "entry::{$id}";
    }

    public function placeholder(Field $field, array $siblings): ?array
    {
        if ($field->type !== 'link') {
            return null;
        }

        $values = [$field->handle => self::PLACEHOLDER_URL];

        foreach ($this->textFor($field->handle, $siblings) as $text) {
            $values[$text] = self::PLACEHOLDER_TEXT;
        }

        return $values;
    }

    private function general(mixed $value, string $id, string $title): mixed
    {
        if (! is_array($value)) {
            return $value === "entry::{$id}" ? self::SELF : ($title !== '' && $value === $title ? self::TITLE : $value);
        }

        if ($value === [$id]) {
            return self::SELF;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->general($item, $id, $title);
        }

        return $value;
    }

    /**
     * Text fields that hold a link's words, by name: `link_text` for `link`,
     * `button_text` for `button_link`, `text` for `link`.
     *
     * @param  array<int, Field>  $fields
     * @return array<int, string>
     */
    private function textFor(string $link, array $fields): array
    {
        $stem = preg_replace('/_?link$/', '', $link) ?? '';
        $wanted = array_unique(array_filter([$link.'_text', $stem !== '' ? $stem.'_text' : null, $stem !== '' ? $stem.'_label' : null, $link.'_label', $stem === '' ? 'text' : null]));

        return array_values(array_filter(array_map(fn (Field $field) => $field->handle, $fields), fn (string $handle) => in_array($handle, $wanted, true)));
    }
}
