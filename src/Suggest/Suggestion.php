<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use InvalidArgumentException;

/**
 * One suggested edit, as the guide steps through it.
 *
 * - `id` is stable: Anchor::key() of its category and anchor, so the same
 *   suggestion from a later review keeps its decision.
 * - `replacement` is the inline markdown subset (text, **bold**, *italic*,
 *   [links](target)); null for a Fact to check, and for a free finding
 *   with no fix written ("Rewrite it yourself").
 * - `alternatives`: up to two other versions, for "Another version" at no
 *   cost; after them, "Write another" (EditReviews::another()).
 * - `finding`: the id of the free finding it fixes, if any; `free`: found
 *   and fixed with no model.
 */
final class Suggestion
{
    public const ALTERNATIVES = 2;

    /**
     * @param  list<string>  $alternatives
     */
    public function __construct(
        public readonly string $id,
        public readonly Category $category,
        public readonly Anchor $anchor,
        public readonly Reason $reason,
        public readonly ?string $replacement = null,
        public readonly array $alternatives = [],
        public readonly ?FactCheck $fact = null,
        public readonly ?LinkChange $link = null,
        public readonly ?string $finding = null,
        public readonly bool $free = false,
        public SuggestionState $state = SuggestionState::Open,
    ) {
        if (count($alternatives) > self::ALTERNATIVES) {
            throw new InvalidArgumentException('A suggestion has at most '.self::ALTERNATIVES.' alternatives.');
        }
    }

    /**
     * A copy with other alternatives (the ones that passed validation).
     *
     * @param  array<int, string>  $alternatives
     */
    public function withAlternatives(array $alternatives): self
    {
        return new self($this->id, $this->category, $this->anchor, $this->reason, $this->replacement, array_values(array_slice($alternatives, 0, self::ALTERNATIVES)), $this->fact, $this->link, $this->finding, $this->free, $this->state);
    }

    /**
     * For the guide: the front end sees nothing else.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'label' => $this->category->label(),
            'speech' => $this->category->speech(),
            'wording' => $this->category->isWording(),
            'anchor' => $this->anchor->toArray() + ['dotted' => $this->anchor->path->dotted()],
            'reason' => $this->reason->toArray(),
            'replacement' => $this->replacement,
            'alternatives' => $this->alternatives,
            'fact' => $this->fact?->toArray(),
            'link' => $this->link?->toArray(),
            'finding' => $this->finding,
            'free' => $this->free,
            'state' => $this->state->value,
        ];
    }

    /**
     * @param  array<mixed>  $a  From toArray().
     */
    public static function fromArray(array $a): self
    {
        $alternatives = array_values(array_filter(is_array($a['alternatives'] ?? null) ? $a['alternatives'] : [], 'is_string'));

        return new self(
            is_string($a['id'] ?? null) ? $a['id'] : '',
            Category::tryFrom(is_string($a['category'] ?? null) ? $a['category'] : '') ?? Category::Clarity,
            Anchor::fromArray(is_array($a['anchor'] ?? null) ? $a['anchor'] : []),
            Reason::fromArray(is_array($a['reason'] ?? null) ? $a['reason'] : []),
            is_string($a['replacement'] ?? null) ? $a['replacement'] : null,
            array_slice($alternatives, 0, self::ALTERNATIVES),
            is_array($a['fact'] ?? null) ? FactCheck::fromArray($a['fact']) : null,
            is_array($a['link'] ?? null) ? LinkChange::fromArray($a['link']) : null,
            is_string($a['finding'] ?? null) ? $a['finding'] : null,
            ($a['free'] ?? false) === true,
            SuggestionState::tryFrom(is_string($a['state'] ?? null) ? $a['state'] : '') ?? SuggestionState::Open,
        );
    }
}
