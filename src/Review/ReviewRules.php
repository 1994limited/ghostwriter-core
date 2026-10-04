<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionAccess;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Who may do what with comments (§14). Each addon adds its CMS's own
 * checks (may this person edit the entry?) around these, as it does
 * around SessionGuard.
 *
 * - See, add, reply, resolve, reopen, pin again: anyone who can see the
 *   piece (shared under E7, or its owner when conversations aren't shared).
 * - Edit a comment's words: its author, before it is sent.
 * - Delete a comment: its author, or a manager.
 * - Apply comments: whoever may resume the piece, as Send.
 */
final class ReviewRules
{
    public function __construct(private readonly SessionAccess $access) {}

    public function mayComment(Session $session, Viewer $viewer): bool
    {
        return $this->access->canSee($session, $viewer);
    }

    public function mayEdit(Session $session, Thread $thread, Viewer $viewer): bool
    {
        return $this->mayComment($session, $viewer) && $viewer->is($thread->startedBy);
    }

    public function mayDelete(Session $session, Thread $thread, Viewer $viewer): bool
    {
        return $this->mayComment($session, $viewer) && ($viewer->is($thread->startedBy) || $viewer->manager);
    }

    public function mayApply(Session $session, Viewer $viewer): bool
    {
        return $this->access->canResume($session, $viewer);
    }
}
