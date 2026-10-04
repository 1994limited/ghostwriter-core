<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

enum NoteKind: string
{
    /** The editor's comment that starts a thread, or their reply in it. */
    case Comment = 'comment';

    /** Ghostwriter's answer when it changed nothing. */
    case Reply = 'reply';

    /** Ghostwriter's answer with the change it made (a before and after). */
    case Change = 'change';

    /** A line about the thread: put back, refused, conflicted, couldn't run. */
    case System = 'system';
}
