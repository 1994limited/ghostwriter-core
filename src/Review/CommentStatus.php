<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * A sent comment's state, as its pin and the chat show it: worked out from
 * the conversation (Comments::pins()), never stored. A comment not sent
 * yet is the panel's own ("Not sent").
 */
enum CommentStatus: string
{
    case Sending = 'sending';
    case Changed = 'changed';
    case Replied = 'replied';
    case Refused = 'refused';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Resolved = 'resolved';
    case Detached = 'detached';

    public static function of(?CommentOutcome $outcome): self
    {
        return match ($outcome) {
            null => self::Sending,
            CommentOutcome::Changed => self::Changed,
            CommentOutcome::Replied => self::Replied,
            CommentOutcome::Refused => self::Refused,
            CommentOutcome::Skipped => self::Skipped,
            CommentOutcome::Failed => self::Failed,
        };
    }

    /** The panel's words for it. */
    public function label(): string
    {
        return match ($this) {
            self::Sending => 'Revising',
            self::Changed => 'Changed',
            self::Replied => 'Replied',
            self::Refused => 'Not applied',
            self::Skipped => 'Skipped',
            self::Failed => 'Not applied',
            self::Resolved => 'Resolved',
            self::Detached => 'Detached',
        };
    }
}
