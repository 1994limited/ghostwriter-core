<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;

/**
 * How much an entry's age counts, and where it counts less.
 *
 * - Age starts to count `from` months after the entry was last updated,
 *   and counts fully at `full` months.
 * - **Dated groups** (collections and sections with a date field: a
 *   journal, news) weigh age and past years at a quarter
 *   (DATED_WEIGHT): a post from 2023 is meant to be from 2023. Each group
 *   has a switch: off, it weighs like any other.
 *
 * `$dated` is by group handle: true where the group has a date field and
 * its switch is on. fromGroups() builds it from the two lists an addon has.
 */
final class AgePolicy
{
    public const DATED_WEIGHT = 0.25;

    /**
     * @param  array<string, bool>  $dated
     */
    public function __construct(
        public readonly int $from = 6,
        public readonly int $full = 36,
        public readonly array $dated = [],
    ) {}

    /**
     * @param  array<int, string>  $withDateField  The groups with a date field.
     * @param  array<int, string>  $switchedOff  Groups whose quarter weight a manager turned off.
     */
    public static function fromGroups(array $withDateField, array $switchedOff = [], int $from = 6, int $full = 36): self
    {
        $dated = [];

        foreach ($withDateField as $group) {
            $dated[$group] = ! in_array($group, $switchedOff, true);
        }

        return new self($from, $full, $dated);
    }

    /** Whether age and past years weigh less in this group. */
    public function isDated(string $group): bool
    {
        return ($this->dated[$group] ?? false) === true;
    }

    /** What age and past years weigh in this group: 1, or DATED_WEIGHT. */
    public function weight(string $group): float
    {
        return $this->isDated($group) ? self::DATED_WEIGHT : 1.0;
    }

    /** Whole months from one time to another; 0 when the second is earlier. */
    public static function months(DateTimeImmutable $since, DateTimeImmutable $now): int
    {
        if ($now <= $since) {
            return 0;
        }

        $diff = $since->diff($now);

        return $diff->y * 12 + $diff->m;
    }

    /**
     * How much of age's weight an entry gets, from 0 (newer than `from`)
     * to 1 (`full` months or more), before the group's weight.
     */
    public function share(?DateTimeImmutable $updatedAt, DateTimeImmutable $now): float
    {
        if ($updatedAt === null) {
            return 0.0;
        }

        $months = self::months($updatedAt, $now);

        if ($months < $this->from) {
            return 0.0;
        }

        return min(1.0, ($months - $this->from + 1) / max(1, $this->full - $this->from + 1));
    }
}
