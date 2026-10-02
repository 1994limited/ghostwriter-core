<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One published entry the kind finder is shown, as one line.
 */
final class KindSample
{
    /**
     * @param  int|string  $id  What the model is to quote back; the suggestions return it as given.
     * @param  string  $text  The entry's prose; its opening is shown.
     * @param  array<int, string>  $builtAs  The block types its page builder uses, in order, enabled blocks only.
     * @param  string|null  $under  The parent entry's title, for structured groups.
     * @param  string|null  $variantHandle  Its blueprint or entry type handle; a suggestion whose examples share one gets it.
     * @param  string|null  $variantName  Its blueprint or entry type as people see it; shown only when set (set it when the group has several).
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $title,
        public readonly string $text = '',
        public readonly array $builtAs = [],
        public readonly ?string $under = null,
        public readonly ?string $variantHandle = null,
        public readonly ?string $variantName = null,
    ) {}
}
