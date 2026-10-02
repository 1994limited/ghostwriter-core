<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * How one CMS stores links, for the house style: which fields hold them,
 * how a link from an entry to itself looks (a breadcrumb's last step), and
 * what goes in a link field that should be set but that nothing settles.
 *
 * Core has one for each CMS, as the shapes are plain arrays and strings:
 * StatamicLinks (`link` and `entries` fields, `entry::id`), CraftLinks
 * (Hyper and Craft's Link field) and NoLinks (Filament, whose forms have
 * no link fields core knows).
 */
interface LinkDialect
{
    /** Stands for the entry itself, and for its title, in a learned value. */
    public const SELF = '@self';

    public const TITLE = '@title';

    /** Where a link that should be there but cannot be decided points. */
    public const PLACEHOLDER_URL = 'https://example.com';

    /** What it says, where the link has words of its own. */
    public const PLACEHOLDER_TEXT = 'Link to choose';

    /**
     * Whether this field holds a link, so the house style counts how often
     * each kind of block has it set.
     */
    public function holdsLinks(Field $field): bool;

    /**
     * Whether a stored link field value points anywhere.
     */
    public function hasLink(mixed $value): bool;

    /**
     * Whether a plain stored value is a link (`entry::12`, a URL), which
     * needs the same agreement as a structured value before it is copied.
     */
    public function looksLikeLink(mixed $value): bool;

    /**
     * A block's values (by field handle) with any link to the entry itself
     * written as self::SELF, and the entry's own title in a link's words as
     * self::TITLE, so it reads the same on every entry.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function generalise(array $values, int|string $id, string $title): array;

    /**
     * What a link to the entry with this ID is stored as, in place of
     * self::SELF.
     */
    public function toSelf(int|string $id): mixed;

    /**
     * The values that make this field a link to example.com, keyed by field
     * handle: the field's own value, and any words for it (applied only
     * where the block has none). Null when the field can't hold a web
     * address.
     *
     * @param  array<int, Field>  $siblings  The other fields of its block.
     * @return array<string, mixed>|null
     */
    public function placeholder(Field $field, array $siblings): ?array;
}
