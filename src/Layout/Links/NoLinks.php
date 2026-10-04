<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Links;

use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkPlaceholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * For a CMS with no link fields core knows: Filament, whose URLs are plain
 * text inputs the writer fills. Nothing is a link, so nothing is pointed at
 * example.com, and no value is read as a link to the entry itself. Rich
 * text and markdown still take a link still to choose inline. No page of
 * the site is linked to inline: Filament's FilamentLinks does that.
 */
final class NoLinks implements InlineLinks, LinkDialect, LinkPlaceholders
{
    use MarksLinks;

    public function holdsLinks(Field $field): bool
    {
        return false;
    }

    public function hasLink(mixed $value): bool
    {
        return false;
    }

    public function looksLikeLink(mixed $value): bool
    {
        return false;
    }

    public function generalise(array $values, int|string $id, string $title): array
    {
        return $values;
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
        return null;
    }

    public function inlineHref(DigestEntry $entry): ?string
    {
        return null;
    }

    public function placeholderFor(Field $field, array $siblings, string $hint): ?array
    {
        return null;
    }
}
