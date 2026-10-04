<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * What Ghostwriter did with one comment, in its answer message.
 */
enum CommentOutcome: string
{
    /** It changed the comment's text (or laid its block out anew). */
    case Changed = 'changed';

    /** It answered without changing anything. */
    case Replied = 'replied';

    /** Its change broke a rule (RevisionValidator), so nothing changed. */
    case Refused = 'refused';

    /** Someone changed its text while Ghostwriter worked, so nothing changed. */
    case Skipped = 'skipped';

    /** The run couldn't happen (a provider error), so nothing changed. */
    case Failed = 'failed';
}
