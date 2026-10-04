<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * Where a comment's thread has got to.
 *
 *     Open ──Apply──▶ Sending ──▶ Changed | Replied ──Resolve──▶ Resolved
 *       ▲                 │                  │                      │
 *       └──── refused, conflicted, failed ───┘◀──── a new reply ────┘ Reopen ▶ Changed | Replied
 *
 * A thread whose units are all gone (a later chat turn rewrote them) is
 * Detached until it is pinned again or resolved.
 */
enum ThreadStatus: string
{
    /** Not sent: new, replied to since the last run, or sent back by the last run. */
    case Open = 'open';

    /** In the run going now ("Revising this block…"). */
    case Sending = 'sending';

    /** Ghostwriter changed its text, with a before and after. */
    case Changed = 'changed';

    /** Ghostwriter answered without changing anything. */
    case Replied = 'replied';

    case Resolved = 'resolved';

    /** Its text is gone from the draft. */
    case Detached = 'detached';

    /** What the panel shows, in English. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Not sent',
            self::Sending => 'Revising',
            self::Changed => 'Changed',
            self::Replied => 'Replied',
            self::Resolved => 'Resolved',
            self::Detached => 'Detached',
        };
    }

    /** Whether Ghostwriter has answered it: Changed or Replied. */
    public function answered(): bool
    {
        return $this === self::Changed || $this === self::Replied;
    }
}
