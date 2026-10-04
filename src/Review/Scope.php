<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;

/**
 * What a comment is anchored to. The anchor is the draft's units (and
 * extra items, "x1.2", for a block a layout fills from extras), not the
 * block's position, so the comment follows its words into another layout
 * and through the next chat turn (Arrange\UnitMatcher). A comment on some
 * words also keeps the quote (Anchor\TextQuote).
 *
 * `label`, `planId` and `blockPath` say where it was made ("Visits list",
 * on layout "w", at "page_builder/2"), for the thread's heading only.
 */
final class Scope
{
    /**
     * @param  list<string>  $units  Unit ids ("u4"), and extra item ids ("x1.2"). Empty for the page.
     * @param  int|null  $sub  The card, when the comment was on one card of several made from one unit.
     */
    public function __construct(
        public readonly ScopeKind $kind,
        public readonly array $units = [],
        public readonly ?string $label = null,
        public readonly ?string $planId = null,
        public readonly ?string $blockPath = null,
        public readonly ?int $sub = null,
        public readonly ?TextQuote $quote = null,
        public readonly ?string $field = null,
    ) {
        if ($kind !== ScopeKind::Page && $units === []) {
            throw new InvalidArgumentException('A comment on a block, words or a field needs the units it is about.');
        }

        if ($kind === ScopeKind::Text && ($quote === null || count($units) !== 1)) {
            throw new InvalidArgumentException('A comment on some words needs the quote and the one unit it is in.');
        }

        if ($kind === ScopeKind::Field && ($field === null || $field === '')) {
            throw new InvalidArgumentException('A comment on a field needs the field.');
        }
    }

    public static function page(?string $label = null, ?string $planId = null): self
    {
        return new self(ScopeKind::Page, [], $label, $planId);
    }

    /**
     * @param  list<string>  $units  The block's units: Arrange\Units::inBlock(), or the refs a layout placed in it.
     */
    public static function block(array $units, ?string $label = null, ?string $planId = null, ?string $blockPath = null, ?int $sub = null): self
    {
        return new self(ScopeKind::Block, self::ids($units), $label, $planId, $blockPath, $sub);
    }

    public static function text(string $unit, TextQuote $quote, ?string $label = null, ?string $planId = null, ?string $blockPath = null): self
    {
        return new self(ScopeKind::Text, [$unit], $label, $planId, $blockPath, null, $quote);
    }

    /**
     * @param  list<string>  $units  The field's units: Arrange\Units::at().
     */
    public static function field(string $field, array $units, ?string $label = null): self
    {
        return new self(ScopeKind::Field, self::ids($units), $label, null, null, null, null, $field);
    }

    /**
     * The units and extra items a revision for this comment may change, in
     * the order given: every unit (and every extra item given) for the
     * page, else its own that the draft still has.
     *
     * @param  list<string>  $extras  The session's extra item ids, for a comment on the page.
     * @return list<string>
     */
    public function editableUnits(Units $units, array $extras = []): array
    {
        if ($this->kind === ScopeKind::Page) {
            return [...$units->ids(), ...$extras];
        }

        return array_values(array_filter($this->units, fn (string $id) => ! self::isUnit($id) || $units->get($id) !== null));
    }

    /**
     * The same scope anchored to other units (after a revision, or pinned again).
     *
     * @param  list<string>  $units
     */
    public function withUnits(array $units, ?TextQuote $quote = null): self
    {
        return new self($this->kind, self::ids($units), $this->label, $this->planId, $this->blockPath, $this->sub, $quote ?? $this->quote, $this->field);
    }

    /** Whether an id is a unit's ("u7"), not an extra item's ("x1.2"). */
    public static function isUnit(string $id): bool
    {
        return preg_match('/^u\d+$/', $id) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind->value,
            'units' => $this->units,
            'label' => $this->label,
            'planId' => $this->planId,
            'blockPath' => $this->blockPath,
            'sub' => $this->sub,
            'quote' => $this->quote?->toArray(),
            'field' => $this->field,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $text = fn (string $key): ?string => is_scalar($array[$key] ?? null) && (string) $array[$key] !== '' ? (string) $array[$key] : null;
        $quote = is_array($array['quote'] ?? null) && is_scalar($array['quote']['exact'] ?? null) && trim((string) $array['quote']['exact']) !== '' ? TextQuote::fromArray($array['quote']) : null;
        $units = self::ids(is_array($array['units'] ?? null) ? $array['units'] : []);
        $kind = ScopeKind::tryFrom($text('kind') ?? '') ?? ScopeKind::Block;

        // A stored scope that no longer holds together is kept as the page's, never lost.
        try {
            return new self($kind, $units, $text('label'), $text('planId'), $text('blockPath'), is_int($array['sub'] ?? null) ? $array['sub'] : null, $quote, $text('field'));
        } catch (InvalidArgumentException) {
            return new self($units === [] ? ScopeKind::Page : ScopeKind::Block, $units, $text('label'), $text('planId'), $text('blockPath'));
        }
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(fn ($id) => trim((string) $id), array_filter($ids, fn ($id) => is_scalar($id) && trim((string) $id) !== ''))));
    }
}
