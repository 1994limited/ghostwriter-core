<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * One reason a plan can't be used (PlanValidator), for the log.
 */
final class Violation
{
    /** A block type the field has no set for, or a child block its parent can't hold. */
    public const UNKNOWN_BLOCK = 'unknown-block';

    /** A field the plan arranges, or places into, that isn't there. */
    public const UNKNOWN_FIELD = 'unknown-field';

    /** A ref that stands for no unit, piece or extra item. */
    public const UNKNOWN_REF = 'unknown-ref';

    /** What's placed is not what the field holds: two paragraphs in a heading, an image in text. */
    public const KIND = 'kind';

    /** More or fewer blocks than the field allows. */
    public const LIMITS = 'limits';

    /** A required field of a block, or of the entry, left empty. */
    public const REQUIRED = 'required';

    /** A block with nothing in it that should have words. */
    public const EMPTY_BLOCK = 'empty-block';

    /** A unit, piece or extra item placed twice. */
    public const DUPLICATED = 'duplicated';

    /** A unit or piece of the arranged fields not placed. */
    public const MISSING = 'missing';

    /** A unit of a field the plan doesn't arrange placed anyway: it would be there twice. */
    public const OUTSIDE = 'outside';

    /** The arranged words aren't the units' words (and the placed extras'). */
    public const WORDS = 'words';

    /** An `[[ask: …]]` or `#gw-link:` lost or doubled. */
    public const MARKERS = 'markers';

    /** An extra item with no source that isn't waiting on an answer. */
    public const UNSOURCED_EXTRA = 'unsourced-extra';

    /** Words placed in a block the site copies whole. */
    public const BOILERPLATE = 'boilerplate';

    /** The same layout as an earlier plan. */
    public const SAME = 'same';

    /** Headings made (lead-in-to-heading, heading-level, an hN construct) in a field whose editor shows none. */
    public const NO_HEADINGS = 'no-headings-here';

    /** Building the entry from it says something is wrong. */
    public const ROUND_TRIP = 'round-trip';

    public function __construct(
        public readonly string $rule,
        public readonly string $message,
    ) {}

    public function __toString(): string
    {
        return "{$this->rule}: {$this->message}";
    }
}
