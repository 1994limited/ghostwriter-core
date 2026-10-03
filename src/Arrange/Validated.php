<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * What PlanValidator::validate() made of some plans: the ones kept, in
 * order, and each dropped one's violations by its id, so a layout that
 * never appeared can be explained ("p2: required, words").
 */
final class Validated
{
    /**
     * @param  list<Plan>  $kept
     * @param  array<string, list<Violation>>  $dropped  By plan id.
     */
    public function __construct(
        public readonly array $kept = [],
        public readonly array $dropped = [],
    ) {}

    /**
     * The rules that dropped each plan, by its id, each rule once.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_map(fn (array $violations) => self::rulesOf($violations), $this->dropped);
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<string>
     */
    public static function rulesOf(array $violations): array
    {
        return array_values(array_unique(array_map(fn (Violation $violation) => $violation->rule, $violations)));
    }
}
