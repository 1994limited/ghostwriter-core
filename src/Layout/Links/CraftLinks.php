<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Links;

use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Craft's links: Hyper fields, which hold a list of links each with
 * `linkValue` and `linkText`, and Craft's own Link field, with `value` and
 * `label`. A link to an entry holds its element ID (or `[id]`); a link to
 * the entry itself is stored back as `[id]`.
 *
 * Which field classes are which is given by the adapter, so core names no
 * Craft class:
 *
 *     new CraftLinks(hyper: [HyperField::class], link: [Link::class])
 */
final class CraftLinks implements LinkDialect
{
    /** Keys that hold where a link goes, in Hyper's and Craft's link fields. */
    private const TARGETS = ['linkValue', 'value'];

    /** Keys that hold what a link says. */
    private const TEXTS = ['linkText', 'label'];

    /** Hyper's own link type for a URL, as its placeholder is stored. */
    public const HYPER_URL = 'verbb\\hyper\\links\\Url';

    /**
     * @param  array<int, string>  $hyper  Field types (classes) that are Hyper fields.
     * @param  array<int, string>  $link  Field types that are Craft's Link field.
     */
    public function __construct(
        private readonly array $hyper = [],
        private readonly array $link = [],
    ) {}

    public function holdsLinks(Field $field): bool
    {
        return in_array($field->type, $this->hyper, true) || in_array($field->type, $this->link, true);
    }

    public function hasLink(mixed $value): bool
    {
        if (! is_array($value)) {
            return is_string($value) && trim($value) !== '';
        }

        foreach ($value as $key => $item) {
            $found = in_array($key, self::TARGETS, true) ? $item !== null && $item !== '' && $item !== [] : is_array($item) && $this->hasLink($item);

            if ($found) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeLink(mixed $value): bool
    {
        return false;
    }

    public function generalise(array $values, int|string $id, string $title): array
    {
        $general = $this->general($values, $id, $title);

        return is_array($general) ? $general : $values;
    }

    /**
     * @return array<int, int|string>
     */
    public function toSelf(int|string $id): array
    {
        return [$id];
    }

    public function placeholder(Field $field, array $siblings): ?array
    {
        if (in_array($field->type, $this->hyper, true)) {
            return [$field->handle => [['type' => self::HYPER_URL, 'linkValue' => self::PLACEHOLDER_URL, 'linkText' => self::PLACEHOLDER_TEXT]]];
        }

        if (in_array($field->type, $this->link, true)) {
            return [$field->handle => ['type' => 'url', 'value' => self::PLACEHOLDER_URL, 'label' => self::PLACEHOLDER_TEXT]];
        }

        return null;
    }

    private function general(mixed $value, int|string $id, string $title): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (in_array($key, self::TARGETS, true) && $this->isId($item, $id)) {
                $value[$key] = self::SELF;
            } elseif (in_array($key, self::TEXTS, true) && $title !== '' && $item === $title) {
                $value[$key] = self::TITLE;
            } else {
                $value[$key] = $this->general($item, $id, $title);
            }
        }

        return $value;
    }

    /**
     * The entry's ID as a link target holds it: the ID, as a number or a
     * string, or a list of just that ID.
     */
    private function isId(mixed $item, int|string $id): bool
    {
        if (is_array($item) && count($item) === 1) {
            $item = reset($item);
        }

        return (is_int($item) || is_string($item)) && (string) $item === (string) $id;
    }
}
