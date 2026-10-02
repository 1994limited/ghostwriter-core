<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One collection, section or resource the planner plans for.
 */
final class PlanGroup
{
    /**
     * @param  array<int, ContentKind>  $kinds  Kinds learned here; handle, title and description are used.
     * @param  array<int, PlanItem>  $items  What it holds, newest first.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $handle,
        public readonly array $kinds = [],
        public readonly array $items = [],
    ) {}
}
