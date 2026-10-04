<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Who may see a review and decide on its suggestions, following
 * `shared_conversations` (E7). Shared (the default): anyone who can edit
 * the entry. Off: the person who started it, and admins. Either way, only
 * people who can edit the entry; the addon says whether they can.
 */
final class EditReviewAccess
{
    public function __construct(private readonly bool $shared = true) {}

    /** As the addon's domain options say (`shared_conversations`). */
    public static function from(DomainOptions $options): self
    {
        return new self($options->shared);
    }

    public function canSee(EditReview $review, Viewer $viewer, bool $canEditEntry): bool
    {
        return $canEditEntry && ($this->shared || $viewer->admin || $viewer->is($review->startedBy));
    }

    public function canDecide(EditReview $review, Viewer $viewer, bool $canEditEntry): bool
    {
        return $this->canSee($review, $viewer, $canEditEntry) && $review->status !== ReviewStatus::Queued && $review->status !== ReviewStatus::Running;
    }
}
