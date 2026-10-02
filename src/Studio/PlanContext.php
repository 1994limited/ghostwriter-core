<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * Everything the planner is shown: the groups to plan for, what is already
 * on the plan, the voice guide and the person's steer.
 */
final class PlanContext
{
    /**
     * @param  array<int, PlanGroup>  $groups
     * @param  array<int, PlannedIdea>  $plan
     * @param  string  $steer  What the person is looking for this time; empty for anything.
     * @param  int  $count  How many ideas to ask for.
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $plan = [],
        public readonly string $voice = '',
        public readonly string $steer = '',
        public readonly int $count = 8,
    ) {}
}
