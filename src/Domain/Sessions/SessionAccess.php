<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Who may see, carry on and delete a piece (E7, Q1; the shared shape of the
 * STA-1 and CRA-3 fixes). Whether someone may use Ghostwriter at all is the
 * addon's own check, made before this one; in Filament the workspace
 * scope also keeps tenants apart.
 *
 * - **Shared** (the default): everyone who may use Ghostwriter sees every
 *   piece and may carry it on. Only the person who started it, or someone
 *   who manages Ghostwriter, may delete it.
 * - **Private** (`shared_conversations` off): each piece is its starter's
 *   alone, to see, carry on and delete.
 *
 * Statamic's options add two more: a piece with no starter on record is
 * anyone's, and a super user may see and delete every piece.
 */
final class SessionAccess
{
    public function __construct(private readonly DomainOptions $options) {}

    public function shared(): bool
    {
        return $this->options->shared;
    }

    /**
     * Whether the person may open the piece, write in it, use its draft or
     * choose its images: resume it.
     */
    public function canSee(Session $session, Viewer $viewer): bool
    {
        if (! $viewer->isSomeone()) {
            return false;
        }

        return $this->options->shared || $this->owns($session, $viewer);
    }

    public function canResume(Session $session, Viewer $viewer): bool
    {
        return $this->canSee($session, $viewer);
    }

    /**
     * Whether the person may delete the piece and its conversation: its
     * starter, or a manager, when shared (Q1); whoever may see it when not.
     */
    public function canDelete(Session $session, Viewer $viewer): bool
    {
        if (! $this->canSee($session, $viewer)) {
            return false;
        }

        return ! $this->options->shared || $this->owns($session, $viewer) || $viewer->manager;
    }

    /**
     * The pieces the person may see, in the order given.
     *
     * @param  iterable<Session>  $sessions
     * @return array<int, Session>
     */
    public function visible(iterable $sessions, Viewer $viewer): array
    {
        $out = [];

        foreach ($sessions as $session) {
            if ($this->canSee($session, $viewer)) {
                $out[] = $session;
            }
        }

        return $out;
    }

    /**
     * Whether it is the person's own piece: they started it (or, with
     * Statamic's options, nobody is on record as starting it, or they are a
     * super user).
     */
    public function owns(Session $session, Viewer $viewer): bool
    {
        if ($viewer->is($session->startedBy)) {
            return true;
        }

        if ($this->options->unownedIsAnyones && ($session->startedBy === null || $session->startedBy === '') && $viewer->isSomeone()) {
            return true;
        }

        return $this->options->adminSeesAll && $viewer->admin && $viewer->isSomeone();
    }
}
