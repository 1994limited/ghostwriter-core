<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

/**
 * Where a piece's conversation has got to, from the brief to the draft
 * (BriefThread::stage()):
 *
 *     Details ──reply──▶ Filling ──▶ Proposed ──"Looks right, start writing"──▶ Writing ──▶ Questions ──▶ Drafting
 *                          ▲           │                                          └──────────────────────▶ Drafting
 *                          └─"Try again"┘
 *
 * "Draft this" from the plan starts at Filling. A piece started from the
 * old brief screen, or one editing an existing record, is past the brief
 * from its first message.
 */
enum BriefStage: string
{
    /** Ghostwriter has asked for the quick details and waits for the person. */
    case Details = 'details';

    /** Ghostwriter is filling in the brief (the session is working), or failed to and can try again. */
    case Filling = 'filling';

    /** The brief card is in the conversation for the person to check. */
    case Proposed = 'proposed';

    /** The brief is agreed and Ghostwriter is starting to write. */
    case Writing = 'writing';

    /** Ghostwriter needs the person's answers before it drafts. */
    case Questions = 'questions';

    /** There is a draft; the conversation is about changes. */
    case Drafting = 'drafting';

    /**
     * Whether the brief has been agreed (or the piece never had a brief card).
     */
    public function agreed(): bool
    {
        return in_array($this, [self::Writing, self::Questions, self::Drafting], true);
    }
}
