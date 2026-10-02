<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * A new entry's data with the house style applied, and the places still
 * for a person to fill (by label, in the order they were found; a place
 * can appear more than once).
 */
final class HouseResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $toFill
     */
    public function __construct(
        public readonly array $data,
        public readonly array $toFill = [],
        private readonly string $item = 'page',
    ) {}

    /**
     * What the person is told about the places still to fill, or null when
     * there are none: "Still to set by hand, as it differs from page to
     * page: Hero (links to example.com for now)."
     */
    public function note(): ?string
    {
        if ($this->toFill === []) {
            return null;
        }

        return "Still to set by hand, as it differs from {$this->item} to {$this->item}: ".implode('; ', array_unique($this->toFill)).'.';
    }
}
