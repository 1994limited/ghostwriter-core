<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * Where a suggestion stands. Accepted, Dismissed and Confirmed are
 * decisions, kept as the page's history with who and when; Done and Stale
 * come from the saved entry (Reconciler); Expired is an open suggestion
 * nobody acted on within EditReview::EXPIRES_DAYS.
 */
enum SuggestionState: string
{
    case Open = 'open';

    /** Put into someone's form; Done once the entry is saved with it. */
    case Accepted = 'accepted';

    case Dismissed = 'dismissed';

    /** A Fact to check: "It's still right". */
    case Confirmed = 'confirmed';

    /** The entry, as saved, has the change. */
    case Done = 'done';

    /** Its quote is no longer in the field. */
    case Stale = 'stale';

    /** Nobody acted on it within 14 days. */
    case Expired = 'expired';

    /** Whether it's still to step through. */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** Whether it's a person's decision, kept as history. */
    public function isDecision(): bool
    {
        return in_array($this, [self::Accepted, self::Dismissed, self::Confirmed], true);
    }
}
