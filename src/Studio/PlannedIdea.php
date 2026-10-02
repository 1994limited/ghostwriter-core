<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * An idea already on the plan, whatever its status.
 */
final class PlannedIdea
{
    public function __construct(
        public readonly string $title,
        public readonly string $group,
        public readonly string $status,
    ) {}
}
