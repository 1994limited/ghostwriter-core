<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;

/**
 * Where a finding or a suggestion is: a field (by FieldPath, blocks by ID),
 * and in it a quoted range (Anchor\TextQuote), the whole value, or an
 * asset's alt text.
 *
 * - `occurrence`: which of the quote's repeats in the field it is (0 for
 *   the first), for when the context can't tell.
 * - `fieldHash`: the field's normalised text when it was read; a Field
 *   anchor is stale once the value changes.
 * - `passage`: a hash of the sentence or sentences the quote is in (the
 *   whole value for a Field anchor; the asset and its alt for an Asset
 *   one). A decision ("It's still right", Dismiss) lasts until it changes.
 */
final class Anchor
{
    public function __construct(
        public readonly AnchorScope $scope,
        public readonly FieldPath $path,
        public readonly string $label,
        public readonly ?TextQuote $quote = null,
        public readonly int $occurrence = 0,
        public readonly ?AssetRef $asset = null,
        public readonly ?string $fieldHash = null,
        public readonly ?string $passage = null,
    ) {}

    /** The quote's words, lower-cased, as stable ids compare them. */
    public function normalisedQuote(): string
    {
        return $this->quote === null ? '' : implode(' ', NormalisedText::words($this->quote->exact));
    }

    /**
     * The stable id of a suggestion of a category here: "out-of-date|page_builder/#h1/eyebrow|new for 2024|0".
     * The same suggestion from a later review, or a free finding and the
     * model's fix for it, have the same id, so decisions carry over.
     */
    public function key(Category $category): string
    {
        $what = match ($this->scope) {
            AnchorScope::Asset => $this->asset?->key() ?? '',
            AnchorScope::Field => '',
            AnchorScope::Range => $this->normalisedQuote(),
        };

        return $category->value.'|'.$this->path->toString().'|'.$what.'|'.$this->occurrence;
    }

    /** A hash of some text as anchors compare it: normalised, lower-cased words. */
    public static function hash(string $text): string
    {
        return substr(sha1(implode(' ', NormalisedText::words($text))), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'scope' => $this->scope->value,
            'path' => $this->path->toString(),
            'label' => $this->label,
            'quote' => $this->quote?->toArray(),
            'occurrence' => $this->occurrence,
            'asset' => $this->asset?->toArray(),
            'fieldHash' => $this->fieldHash,
            'passage' => $this->passage,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $asset = is_array($array['asset'] ?? null) ? $array['asset'] : null;
        $assetId = $asset['id'] ?? null;

        return new self(
            AnchorScope::tryFrom(is_string($array['scope'] ?? null) ? $array['scope'] : '') ?? AnchorScope::Field,
            FieldPath::parse(is_string($array['path'] ?? null) && $array['path'] !== '' ? $array['path'] : 'unknown'),
            is_string($array['label'] ?? null) ? $array['label'] : '',
            is_array($array['quote'] ?? null) ? TextQuote::fromArray($array['quote']) : null,
            is_int($array['occurrence'] ?? null) ? $array['occurrence'] : 0,
            $asset === null ? null : new AssetRef(
                is_string($asset['volume'] ?? null) ? $asset['volume'] : '',
                is_string($asset['path'] ?? null) ? $asset['path'] : '',
                is_int($assetId) || is_string($assetId) ? $assetId : null,
            ),
            is_string($array['fieldHash'] ?? null) ? $array['fieldHash'] : null,
            is_string($array['passage'] ?? null) ? $array['passage'] : null,
        );
    }
}
