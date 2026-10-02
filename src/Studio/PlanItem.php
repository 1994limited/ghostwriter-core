<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One existing entry, as the planner sees it: its title, whether it is
 * live, and a short summary.
 */
final class PlanItem
{
    /** How much of an entry's prose fromProse() keeps. */
    public const SUMMARY_LENGTH = 160;

    /**
     * @param  string  $summary  Shown as it is; see fromProse() for the usual way to make one.
     */
    public function __construct(
        public readonly string $title,
        public readonly bool $published = true,
        public readonly string $summary = '',
    ) {}

    /**
     * With the opening of the entry's prose as its summary: the first 160
     * characters, white space collapsed (as Craft and Filament do).
     */
    public static function fromProse(string $title, bool $published, string $prose): self
    {
        return new self($title, $published, Studio::opening($prose, self::SUMMARY_LENGTH));
    }
}
