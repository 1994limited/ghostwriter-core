<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * A check whose answer changes with time alone: when a closing date
 * passes, or a new year makes "New for 2026" a past year. It says when,
 * so the revisit index scans the entry again on that day without reading
 * every entry every day.
 */
interface Watches
{
    /**
     * Days on which the check's answer for this entry may change, after now.
     *
     * @return list<DateTimeImmutable>
     */
    public function watch(CheckContext $context): array;
}
