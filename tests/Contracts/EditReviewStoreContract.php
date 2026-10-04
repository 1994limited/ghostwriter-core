<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Ulid;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Anchor;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Reason;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReasonSource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;

/**
 * What every EditReviewStore must do: reviews kept whole with their
 * history, the newest per entry, the version check, and expiry due dates.
 * Reviews are never deleted by core; delete() is for a deleted entry.
 */
trait EditReviewStoreContract
{
    abstract protected function editReviewStore(): EditReviewStore;

    /** An entry reference the addon's store can hold (its group, id and site shapes). */
    protected function reviewedEntry(string $id = 'services'): EntryRef
    {
        return new EntryRef('pages', $id, 'default');
    }

    private function review(?EntryRef $entry = null, ?string $finishedAt = '2026-10-04T10:00:00+00:00'): EditReview
    {
        $suggestion = new Suggestion(
            'voice|page_builder/#h1/heading|bespoke|0',
            Category::Voice,
            new Anchor(AnchorScope::Range, FieldPath::parse('page_builder/#h1/heading'), 'Hero: Heading', new TextQuote('bespoke', 'deliver ', ' garden'), passage: 'abc'),
            new Reason('Jargon.', ReasonSource::VoiceGuide, 'What this voice never does'),
            'made to measure',
            ['tailored'],
        );

        return new EditReview(
            Ulid::generate(),
            $entry ?? $this->reviewedEntry(),
            ReviewStatus::Ready,
            7,
            'hash1',
            [$suggestion->toArray()],
            usage: ['input' => 9000, 'output' => 1800],
            calls: 1,
            createdAt: '2026-10-04T09:59:00+00:00',
            finishedAt: $finishedAt,
            expiresAt: $finishedAt === null ? null : (new DateTimeImmutable($finishedAt))->modify('+14 days')->format(DATE_ATOM),
        );
    }

    public function test_a_review_is_kept_whole_with_its_history(): void
    {
        $store = $this->editReviewStore();
        $review = $this->review();
        $review->decide('voice|page_builder/#h1/heading|bespoke|0', SuggestionState::Dismissed, new Viewer(7), new DateTimeImmutable('2026-10-04 11:00'));
        $saved = $store->save($review);

        $this->assertSame(1, $saved->version);
        $found = $store->find($review->id);
        $this->assertNotNull($found);
        $this->assertSame($saved->toArray(), $found->toArray());
        $this->assertSame(SuggestionState::Dismissed, $found->all()[0]->state);
        $this->assertSame(7, $found->decisions[0]->by);
        $this->assertNull($store->find(Ulid::generate()));
    }

    public function test_the_version_must_match(): void
    {
        $store = $this->editReviewStore();
        $saved = $store->save($this->review());
        $stale = $store->find($saved->id);
        $this->assertNotNull($stale);

        $store->save($saved);

        $this->expectException(Conflict::class);
        $stale->version = 0;
        $store->save($stale);
    }

    public function test_latest_and_history_per_entry_newest_first(): void
    {
        $store = $this->editReviewStore();
        $first = $store->save($this->review());
        $other = $store->save($this->review($this->reviewedEntry('about')));
        $second = $store->save($this->review());

        $this->assertSame($second->id, $store->latestFor($this->reviewedEntry())?->id);
        $this->assertSame([$second->id, $first->id], array_map(fn (EditReview $r) => $r->id, $store->history($this->reviewedEntry())));
        $this->assertSame([$other->id], array_map(fn (EditReview $r) => $r->id, $store->history($this->reviewedEntry('about'))));
        $this->assertCount(1, $store->history($this->reviewedEntry(), 1));
        $this->assertNull($store->latestFor($this->reviewedEntry('nothing')));
    }

    public function test_reviews_due_to_expire(): void
    {
        $store = $this->editReviewStore();
        $due = $store->save($this->review(finishedAt: '2026-09-01T10:00:00+00:00'));
        $notYet = $store->save($this->review());
        $running = $this->review(finishedAt: null);
        $running->status = ReviewStatus::Running;
        $store->save($running);

        $ids = $store->dueToExpire(new DateTimeImmutable('2026-10-04 12:00'));

        $this->assertContains($due->id, $ids);
        $this->assertNotContains($notYet->id, $ids);
        $this->assertNotContains($running->id, $ids);
    }

    public function test_delete_is_for_a_deleted_entry(): void
    {
        $store = $this->editReviewStore();
        $review = $store->save($this->review());
        $store->delete($review->id);
        $store->delete($review->id);

        $this->assertNull($store->find($review->id));
        $this->assertNull($store->latestFor($this->reviewedEntry()));
    }
}
