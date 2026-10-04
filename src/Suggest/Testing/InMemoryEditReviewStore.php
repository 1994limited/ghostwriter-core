<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;

/**
 * An EditReviewStore in memory, for tests and the demo, and the reference
 * for what the port must do (EditReviewStoreContract). Reviews are kept
 * as arrays, as a real store keeps them.
 */
final class InMemoryEditReviewStore implements EditReviewStore
{
    /** @var array<string, array<string, mixed>> In the order they were first saved. */
    private array $reviews = [];

    public function find(string $id): ?EditReview
    {
        return isset($this->reviews[$id]) ? EditReview::fromArray($this->reviews[$id]) : null;
    }

    public function latestFor(EntryRef $entry): ?EditReview
    {
        return $this->history($entry, 1)[0] ?? null;
    }

    public function history(EntryRef $entry, int $limit = 50): array
    {
        $found = [];

        foreach (array_reverse($this->reviews) as $review) {
            $review = EditReview::fromArray($review);

            if ($review->entry->is($entry)) {
                $found[] = $review;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function save(EditReview $review): EditReview
    {
        $stored = $this->reviews[$review->id] ?? null;

        if ($stored !== null && ($stored['version'] ?? 0) !== $review->version) {
            throw new Conflict('Someone else changed this review. Try again.');
        }

        $review->version++;
        $this->reviews[$review->id] = $review->toArray();

        return EditReview::fromArray($this->reviews[$review->id]);
    }

    public function dueToExpire(DateTimeInterface $now, int $limit = 100): array
    {
        $due = [];

        foreach ($this->reviews as $id => $array) {
            $review = EditReview::fromArray($array);

            if (in_array($review->status, [ReviewStatus::Ready, ReviewStatus::Failed], true) && $review->isDue(DateTimeImmutable::createFromInterface($now))) {
                $due[] = (string) $id;
            }
        }

        return array_slice($due, 0, $limit);
    }

    public function delete(string $id): void
    {
        unset($this->reviews[$id]);
    }
}
