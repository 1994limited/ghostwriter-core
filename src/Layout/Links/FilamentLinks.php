<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Links;

use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkPlaceholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * Filament's links: as NoLinks (its forms have no link fields core knows),
 * and an inline link to another record is the plain public address the
 * resource's `->publicUrlUsing()` gives (SEO layer §7.6), which the link
 * index stores as the row's link. A RichEditor keeps it as an `<a href>`.
 * A record with no public address can't be linked to.
 */
final class FilamentLinks implements InlineLinks, LinkDialect, LinkPlaceholders
{
    private readonly NoLinks $none;

    public function __construct()
    {
        $this->none = new NoLinks;
    }

    public function inlineHref(DigestEntry $entry): ?string
    {
        foreach ([$entry->link, $entry->url] as $address) {
            $address = is_string($address) ? trim($address) : '';

            if (preg_match('#^(?:https?://[^\s()<>]+|/[^\s()<>]*)$#i', $address) === 1) {
                return $address;
            }
        }

        return null;
    }

    public function holdsLinks(Field $field): bool
    {
        return $this->none->holdsLinks($field);
    }

    public function hasLink(mixed $value): bool
    {
        return $this->none->hasLink($value);
    }

    public function looksLikeLink(mixed $value): bool
    {
        return $this->none->looksLikeLink($value);
    }

    public function generalise(array $values, int|string $id, string $title): array
    {
        return $this->none->generalise($values, $id, $title);
    }

    /**
     * @return array<int, int|string>
     */
    public function toSelf(int|string $id): array
    {
        return $this->none->toSelf($id);
    }

    public function placeholder(Field $field, array $siblings): ?array
    {
        return $this->none->placeholder($field, $siblings);
    }

    public function placeholderFor(Field $field, array $siblings, string $hint): ?array
    {
        return $this->none->placeholderFor($field, $siblings, $hint);
    }

    public function supportsLinks(Field $field): bool
    {
        return $this->none->supportsLinks($field);
    }
}
