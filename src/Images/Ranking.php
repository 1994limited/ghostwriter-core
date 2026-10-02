<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

/**
 * What PhotoRanker made of a set of candidates.
 *
 * - judged, not noneFit: `photos` are the ones the model said belong, best
 *   first, the shortlist picked; clear misses are left out.
 * - judged and noneFit: the model looked and none belong; `photos` are the
 *   candidates unranked, and `retryTerms` the searches it suggested instead.
 * - not judged (no model, no candidates, no thumbnails, a failed call):
 *   `photos` are the candidates unranked, none picked.
 */
final class Ranking
{
    /**
     * @param  array<int, Photo>  $photos
     * @param  array<int, string>  $retryTerms
     */
    public function __construct(
        public readonly array $photos,
        public readonly bool $judged,
        public readonly bool $noneFit = false,
        public readonly array $retryTerms = [],
        public readonly bool $withReferences = false,
    ) {}
}
