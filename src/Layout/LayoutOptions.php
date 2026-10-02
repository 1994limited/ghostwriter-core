<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use Closure;
use InvalidArgumentException;

/**
 * The few things the three addons' layout code did differently that are
 * not about how the CMS stores rich text or links (those are the
 * dialects). statamic(), craft() and filament() give each addon's
 * behaviour as it was before core; docs/layout-unification.md lists them.
 */
final class LayoutOptions
{
    /** Unsettled references are named when they sit inside a block (Craft, Filament). */
    public const NAME_NESTED = 'nested';

    /** Only links a block usually has, and that can't be stood in for, are named (Statamic). */
    public const NAME_LINKS = 'links';

    /** @var (Closure(): string)|null */
    public readonly ?Closure $newId;

    /** @var (Closure(array<int, string>, int): string)|null */
    public readonly ?Closure $kindLabel;

    /**
     * @param  string  $group  What a group of entries is called in the brief: "Entries in this collection usually build…".
     * @param  string  $item  What one entry is called in the house style's note: "as it differs from page to page".
     * @param  array<int, string>  $bookkeeping  Entry keys that are never a house default (the title, the slug, a CMS's own dates).
     * @param  bool  $richTextInPositions  Whether a block's rich text counts among the values the house style copies by position (Craft, Filament), or only its plain values do (Statamic, whose Bard values are documents).
     * @param  string  $unsettled  Which references the house style names as still to fill: NAME_NESTED or NAME_LINKS.
     * @param  (callable(): string)|null  $newId  Makes the ID a new block or row is stored with, where the CMS keeps IDs in the entry's data (Statamic). Null: new blocks have none, and copied content loses its IDs.
     * @param  string  $unknownBlock  How a block type the field doesn't allow is reported: 'A block of type "x" {this} pageBuilder and was left out.'
     * @param  bool  $kindsFromAnyBuilder  Whether the kind finder groups entries by their first page builder even when it has nothing to write in it (Filament), or by their first `blocks` field (Statamic, Craft).
     * @param  (callable(array<int, string>, int): string)|null  $kindLabel  Names a kind after its first titles and how many more there are, for a translated "Like A, B and 3 more". Null: English.
     * @param  bool  $linkSentinels  Whether a link field the house style can't settle is marked as still to choose with the `#gw-link:` sentinel (Gaps\Markers), where the link dialect can (LinkPlaceholders), rather than pointed at example.com. Off by default in 1.x.
     */
    public function __construct(
        public readonly string $group = 'section',
        public readonly string $item = 'page',
        public readonly array $bookkeeping = ['title', 'slug', 'id'],
        public readonly bool $richTextInPositions = true,
        public readonly string $unsettled = self::NAME_NESTED,
        ?callable $newId = null,
        public readonly string $unknownBlock = 'cannot go in',
        public readonly bool $kindsFromAnyBuilder = false,
        ?callable $kindLabel = null,
        public readonly bool $linkSentinels = false,
    ) {
        if (! in_array($unsettled, [self::NAME_NESTED, self::NAME_LINKS], true)) {
            throw new InvalidArgumentException("Unsettled references are named '".self::NAME_NESTED."' or '".self::NAME_LINKS."', not '{$unsettled}'.");
        }

        $this->newId = $newId === null ? null : Closure::fromCallable($newId);
        $this->kindLabel = $kindLabel === null ? null : Closure::fromCallable($kindLabel);
    }

    /**
     * Statamic: collections, Bard values kept out of the house style's
     * positions, links named only where they can't be stood in for, block
     * and row IDs in the entry's data, and Statamic's own entry keys.
     *
     * @param  (callable(): string)|null  $newId  Defaults to eight random hex characters, as Statamic's own sets get.
     */
    public static function statamic(?callable $newId = null): self
    {
        return new self(
            group: 'collection',
            item: 'page',
            bookkeeping: ['title', 'slug', 'date', 'id', 'blueprint', 'published', 'updated_at', 'updated_by'],
            richTextInPositions: false,
            unsettled: self::NAME_LINKS,
            newId: $newId ?? fn (): string => bin2hex(random_bytes(4)),
            unknownBlock: 'does not exist in',
        );
    }

    public static function craft(): self
    {
        return new self;
    }

    /**
     * Filament: records rather than pages, and kinds found from the first
     * Builder whatever is in it.
     *
     * @param  (callable(array<int, string>, int): string)|null  $kindLabel  The panel's translation of "Like :titles" and "Like :titles and :count more".
     */
    public static function filament(?callable $kindLabel = null): self
    {
        return new self(item: 'record', kindsFromAnyBuilder: true, kindLabel: $kindLabel);
    }

    /**
     * The same options with links still to choose marked by the sentinel
     * (or not).
     */
    public function withLinkSentinels(bool $on = true): self
    {
        return new self($this->group, $this->item, $this->bookkeeping, $this->richTextInPositions, $this->unsettled, $this->newId, $this->unknownBlock, $this->kindsFromAnyBuilder, $this->kindLabel, $on);
    }
}
