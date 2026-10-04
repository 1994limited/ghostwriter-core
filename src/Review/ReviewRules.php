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
 * - Send comments (Apply): whoever may resume the piece, as Send.
 * - Resolve or reopen a comment, put a change back: anyone who can see the
 *   piece (shared under E7, or its owner when conversations aren't shared).
 *
 * Comments not sent yet are the editor's own, in their panel.
 */
final class ReviewRules
{
    public function __construct(private readonly SessionAccess $access) {}

    public function mayComment(Session $session, Viewer $viewer): bool
    {
        return $this->access->canSee($session, $viewer);
    }

    public function mayApply(Session $session, Viewer $viewer): bool
    {
        return $this->access->canResume($session, $viewer);
    }
}
