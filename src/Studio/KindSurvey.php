<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * What the kind finder is shown of one group: its newest published entries,
 * the kinds already taught and the suggestions already turned down.
 */
final class KindSurvey
{
    /**
     * @param  array<int, KindSample>  $samples  Newest first.
     * @param  array<int, ContentKind>  $taught  Kinds already learned here; title and description are used.
     * @param  array<int, string>  $dismissed  Titles of suggestions turned down.
     */
    public function __construct(
        public readonly string $groupTitle,
        public readonly string $groupHandle,
        public readonly array $samples,
        public readonly array $taught = [],
        public readonly array $dismissed = [],
    ) {}
}
