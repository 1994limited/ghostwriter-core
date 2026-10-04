<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * What a review comes to after validation: the suggestions to step
 * through, in form order (candidates the model kept and fixed, and its own
 * suggestions), how many of its suggestions were dropped, by reason, with
 * no text, and the candidates checked in context and dropped.
 *
 * - `checked`: each candidate the reviewer dropped (or a suggestion the
 *   verifier dropped), as `['id' => finding or suggestion id, 'passage' =>
 *   its anchor's passage hash, 'reason' => the model's few words, 'by' =>
 *   'reviewer' or 'verifier']`. Not shown; EditReviews keeps them quiet
 *   (Quiet::CHECKED) so the same words aren't a candidate next time.
 * - `verified`: the verifier's verdict on each suggestion it kept, by
 *   suggestion id: 'keep' or 'fix'. Empty when the verifier didn't run.
 */
final class ValidatedReview
{
    /**
     * @param  list<Suggestion>  $suggestions
     * @param  array<string, int>  $dropped
     * @param  list<array{id: string, passage: ?string, reason: string, by: string}>  $checked
     * @param  array<string, string>  $verified
     */
    public function __construct(
        public readonly array $suggestions,
        public readonly array $dropped = [],
        public readonly array $checked = [],
        public readonly array $verified = [],
    ) {}

    /** How many came from the model (not free). */
    public function written(): int
    {
        return count(array_filter($this->suggestions, fn (Suggestion $suggestion) => ! $suggestion->free));
    }
}
