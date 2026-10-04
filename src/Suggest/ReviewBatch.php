<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;

/**
 * One call's share of a review: a long page is split into several calls
 * (ReviewInput::WORDS_PER_CALL each), never splitting a unit, and keeping
 * a field's units together where they fit. Each call sees its own units,
 * the findings in them and their images, and the whole digest.
 */
final class ReviewBatch
{
    /**
     * @param  list<Unit>  $units
     * @param  array<string, Finding>  $findings  By call number: "f1"… (numbered across the whole review).
     * @param  array<string, Finding>  $images  MissingAlt findings, by image number: "i1"…
     */
    public function __construct(
        public readonly int $index,
        public readonly int $total,
        public readonly array $units,
        public readonly array $findings = [],
        public readonly array $images = [],
    ) {}

    public function words(): int
    {
        return array_sum(array_map(fn (Unit $unit) => ReviewInput::wordsIn($unit->markdown), $this->units));
    }

    public function unit(string $id): ?Unit
    {
        foreach ($this->units as $unit) {
            if ($unit->id === $id) {
                return $unit;
            }
        }

        return null;
    }
}
