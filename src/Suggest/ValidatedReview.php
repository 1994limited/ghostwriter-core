<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * What a review comes to after validation: the suggestions to step
 * through, in form order (the model's fixes for findings, its own
 * suggestions, and the free form of every finding it didn't fix), and how
 * many of its suggestions were dropped, by reason, with no text.
 */
final class ValidatedReview
{
    /**
     * @param  list<Suggestion>  $suggestions
     * @param  array<string, int>  $dropped
     */
    public function __construct(
        public readonly array $suggestions,
        public readonly array $dropped = [],
    ) {}

    /** How many came from the model (not free). */
    public function written(): int
    {
        return count(array_filter($this->suggestions, fn (Suggestion $suggestion) => ! $suggestion->free));
    }
}
