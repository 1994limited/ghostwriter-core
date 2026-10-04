<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;

/**
 * Where reviews are kept, implemented by each addon in its own storage
 * style (Statamic: a JSON file per review with a pointer per entry;
 * Craft: `{{%ghostwriter_edit_reviews}}`; Filament: an Eloquent table with
 * the workspace). Reviews are the page's history and are never deleted by
 * core: only their unactioned suggestions expire. Proven by
 * Tests\Contracts\EditReviewStoreContract.
 */
interface EditReviewStore
{
    public function find(string $id): ?EditReview;

    /** The newest review of an entry, whatever its status. */
    public function latestFor(EntryRef $entry): ?EditReview;

    /**
     * Every review of an entry, newest first: the page's history.
     *
     * @return list<EditReview>
     */
    public function history(EntryRef $entry, int $limit = 50): array;

    /**
     * Saves it and returns it with its version one higher.
     *
     * @throws Conflict when `$review->version` isn't the stored one.
     */
    public function save(EditReview $review): EditReview;

    /**
     * Reviews whose expiry has come (`expiresAt` ≤ now), ready or failed,
     * that may still have open suggestions.
     *
     * @return list<string> Their ids.
     */
    public function dueToExpire(DateTimeInterface $now, int $limit = 100): array;

    /** Gone for good: only when the entry itself is deleted. */
    public function delete(string $id): void;
}
