<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewAccess;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\InMemoryEditReviewStore;
use PHPUnit\Framework\TestCase;

/**
 * The EditReview store through EditReviews: a shared review (E7), its
 * decisions kept as history, Write another, the reconciler after a save,
 * and unactioned suggestions expiring after 14 days.
 */
final class EditReviewsTest extends TestCase
{
    private FakeProvider $fake;

    private InMemoryEditReviewStore $store;

    private EditReviews $reviews;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->fake = new FakeProvider;
        $this->fake->respond('reviewer', ReviewCase::reply());
        $this->store = new InMemoryEditReviewStore;
        $this->reviews = new EditReviews($this->store, new InMemoryLock, ReviewCase::studio($this->fake));
        $this->now = new DateTimeImmutable(Northfold::NOW);
    }

    private function ready(): EditReview
    {
        $review = $this->reviews->start(Northfold::ref(), new Viewer(7), $this->now);

        return $this->reviews->run($review->id, ReviewCase::input(), $this->now);
    }

    private function idOf(EditReview $review, string $category): string
    {
        foreach ($review->all() as $suggestion) {
            if ($suggestion->category->value === $category) {
                return $suggestion->id;
            }
        }

        $this->fail("No {$category} suggestion.");
    }

    public function test_a_review_runs_once_and_is_stored_ready(): void
    {
        $review = $this->ready();

        $this->assertSame(ReviewStatus::Ready, $review->status);
        $this->assertCount(7, $review->open());
        $this->assertCount(1, $this->fake->requests(), 'One reviewer call.');
        $this->assertGreaterThan(0, $review->usage['input'] + $review->usage['output']);
        $this->assertSame(1, $review->calls);
        $this->assertSame('2026-10-18T10:00:00+00:00', $review->expiresAt, 'Fourteen days.');
        $this->assertSame(6, count($review->findings), 'What it was given.');
    }

    public function test_one_run_per_entry_at_a_time(): void
    {
        $this->reviews->start(Northfold::ref(), new Viewer(7), $this->now);

        try {
            $this->reviews->start(Northfold::ref(), new Viewer(9), $this->now->modify('+1 minute'));
            $this->fail('A second run should wait.');
        } catch (Busy $busy) {
            $this->assertSame('Priya is reviewing this page. It opens here when it is ready.', $busy->messageFor(fn ($id) => $id === 7 ? 'Priya' : 'someone'));
        }

        $this->assertNotNull($this->reviews->start(Northfold::ref(), new Viewer(9), $this->now->modify('+20 minutes')), 'A run that died is taken over.');
    }

    public function test_decisions_are_kept_as_history_and_undo_adds_to_it(): void
    {
        $review = $this->ready();
        $voice = $this->idOf($review, 'voice');

        $this->reviews->decide($review->id, $voice, SuggestionState::Dismissed, new Viewer(9), $this->now);
        $this->reviews->undo($review->id, $voice, new Viewer(7), $this->now->modify('+1 minute'));
        $after = $this->reviews->decide($review->id, $voice, SuggestionState::Accepted, new Viewer(7), $this->now->modify('+2 minutes'), text: 'We design gardens');

        $this->assertSame(['dismissed', 'open', 'accepted'], array_map(fn ($d) => $d->state->value, $after->decisions));
        $this->assertSame([9, 7, 7], array_map(fn ($d) => $d->by, $after->decisions));
        $this->assertSame(SuggestionState::Accepted, $after->find($voice)?->state);
        $this->assertCount(6, $after->open());
        $this->assertCount(1, $this->fake->requests(), 'Decisions call nothing.');
    }

    public function test_only_a_fact_can_be_confirmed_and_a_settled_suggestion_cannot_change(): void
    {
        $review = $this->ready();
        $fact = $this->idOf($review, 'fact-to-check');

        $confirmed = $this->reviews->decide($review->id, $fact, SuggestionState::Confirmed, new Viewer(7), $this->now);
        $this->assertSame(SuggestionState::Confirmed, $confirmed->find($fact)?->state);

        $this->expectException(Conflict::class);
        $this->reviews->decide($review->id, $this->idOf($review, 'voice'), SuggestionState::Confirmed, new Viewer(7), $this->now);
    }

    public function test_shared_reviews_follow_e7(): void
    {
        $review = $this->ready();

        $this->assertTrue((new EditReviewAccess)->canSee($review, new Viewer(9), true), 'Shared: anyone who can edit the entry.');
        $this->assertFalse((new EditReviewAccess)->canSee($review, new Viewer(9), false));
        $this->assertFalse((new EditReviewAccess(shared: false))->canSee($review, new Viewer(9), true), 'Not shared: only its starter.');
        $this->assertTrue((new EditReviewAccess(shared: false))->canDecide($review, new Viewer(7), true));
    }

    public function test_dismissals_stick_across_reviews_and_quiet_the_free_checks(): void
    {
        $review = $this->ready();
        $year = $this->idOf($review, 'out-of-date');
        $this->reviews->decide($review->id, $year, SuggestionState::Dismissed, new Viewer(7), $this->now);

        $quieted = $this->reviews->quieted(Northfold::ref(), $this->now);
        $this->assertTrue($quieted->covers($year, $review->find($year)?->anchor->passage, $this->now));

        $kinds = array_map(fn (Finding $f) => $f->kind, Findings::standard()->find(Northfold::context(quieted: $quieted)));
        $this->assertNotContains('past-year', $kinds, 'The free check is quiet too.');

        $again = $this->reviews->run($this->reviews->start(Northfold::ref(), new Viewer(7), $this->now)->id, ReviewCase::input(), $this->now);
        $this->assertSame(SuggestionState::Dismissed, $again->find($year)?->state, 'The decision carries over to a new review of the same words.');

        $this->assertFalse($this->reviews->quieted(Northfold::ref(), $this->now->modify('+13 months'))->covers($year, null, $this->now->modify('+13 months')), 'For 12 months.');
    }

    public function test_write_another_is_one_reworder_call_and_keeps_only_versions_that_pass(): void
    {
        $review = $this->ready();
        $voice = $this->idOf($review, 'voice');
        $this->fake->respond('reworder', '<versions><version>We plan gardens and help them grow</version><version>We have planted 900 gardens since 1987</version></versions>');

        $versions = $this->reviews->another($review->id, $voice, ReviewCase::input(), $this->now);

        $this->assertSame(['We plan gardens and help them grow'], $versions, 'The one with invented figures is dropped.');
        $this->assertCount(1, $this->fake->prompted('reworder'));
        $this->assertSame(['We plan gardens and help them grow'], $this->store->find($review->id)?->versions[$voice]);
    }

    public function test_a_failed_call_keeps_the_free_findings(): void
    {
        $this->fake->reset();
        $this->fake->failWith('reviewer', new Overloaded('The model is busy.'));
        $review = $this->reviews->run($this->reviews->start(Northfold::ref(), new Viewer(7), $this->now)->id, ReviewCase::input(), $this->now);

        $this->assertSame(ReviewStatus::Failed, $review->status);
        $this->assertSame('The model is busy.', $review->error);
        $this->assertSame(['out-of-date', 'fact-to-check', 'link', 'accessibility', 'seo'], array_map(fn (Suggestion $s) => $s->category->value, $review->open()));
    }

    public function test_after_a_save_done_and_stale_and_the_rest_unchanged(): void
    {
        $review = $this->ready();
        $fact = $this->idOf($review, 'fact-to-check');
        $this->reviews->decide($review->id, $fact, SuggestionState::Accepted, new Viewer(7), $this->now, answer: '8');

        $saved = Northfold::entry([
            'page_builder' => [
                ['id' => 'h1', 'type' => 'hero', 'eyebrow' => 'Every winter: winter care visits', 'heading' => 'Gardens for people who love them'],
                ['id' => 't1', 'type' => 'text', 'text' => str_replace('team of 6', 'team of 8', Northfold::TEXT)],
                ['id' => 'i1', 'type' => 'image', 'image' => 'assets::materials.jpg'],
            ],
            'seo_description' => 'From a planting plan to a full design and build, across Northumberland, Durham and the Tyne Valley.',
        ]);
        $after = $this->reviews->saved(Northfold::ref(), Northfold::context(entry: $saved), $this->now->modify('+1 hour'));

        $states = [];

        foreach ($after?->all() ?? [] as $suggestion) {
            $states[$suggestion->category->value] = $suggestion->state->value;
        }

        $this->assertSame([
            'out-of-date' => 'done',
            'voice' => 'stale',
            'fact-to-check' => 'done',
            'clarity' => 'open',
            'link' => 'open',
            'accessibility' => 'open',
            'seo' => 'done',
        ], $states);
        $this->assertNull(end($after->decisions)->by, 'Core settles them, with no person.');
    }

    public function test_unactioned_suggestions_expire_after_14_days_and_decisions_stay(): void
    {
        $review = $this->ready();
        $voice = $this->idOf($review, 'voice');
        $this->reviews->decide($review->id, $voice, SuggestionState::Dismissed, new Viewer(7), $this->now);

        $this->assertSame(0, $this->reviews->expire($this->now->modify('+13 days')));
        $this->assertSame(6, $this->reviews->expire($this->now->modify('+15 days')));

        $expired = $this->store->find($review->id);
        $this->assertNotNull($expired);
        $this->assertSame([], $expired->open());
        $this->assertSame(SuggestionState::Dismissed, $expired->find($voice)?->state, 'The decision is kept.');
        $this->assertSame('We design gardens and help them grow', $expired->find($voice)?->replacement, 'A decided suggestion keeps its words.');

        foreach ($expired->all() as $suggestion) {
            if ($suggestion->state === SuggestionState::Expired) {
                $this->assertNull($suggestion->replacement, 'An expired one keeps no words.');
            }
        }

        $this->assertSame(0, $this->reviews->expire($this->now->modify('+16 days')), 'Once.');
        $this->assertCount(1, $this->store->history(Northfold::ref()), 'The review is history, not deleted.');
        $this->assertTrue($this->reviews->quieted(Northfold::ref(), $this->now->modify('+16 days'))->covers($voice, $expired->find($voice)?->anchor->passage, $this->now->modify('+16 days')));
    }

    public function test_preview_is_free(): void
    {
        $this->fake->reset();
        $preview = $this->reviews->preview(Northfold::context(), Northfold::ref());

        $this->assertCount(5, $preview['findings']);
        $this->assertNull($preview['review']);
        $this->fake->assertNothingSent();
    }

    public function test_an_edit_in_the_form_makes_a_stored_review_stale_on_preview(): void
    {
        $this->ready();
        $edited = Northfold::entry(['page_builder' => [
            ['id' => 'h1', 'type' => 'hero', 'eyebrow' => 'Winter care visits', 'heading' => 'We leverage our expertise to deliver bespoke garden solutions'],
            ['id' => 't1', 'type' => 'text', 'text' => Northfold::TEXT],
        ]]);
        $preview = $this->reviews->preview(Northfold::context(entry: new EntryData($edited->values, 'services')), Northfold::ref());
        $states = array_column($preview['review']['suggestions'] ?? [], 'state', 'category');

        $this->assertSame('stale', $states['out-of-date']);
        $this->assertSame('open', $states['voice']);
        $this->assertSame(ReviewStatus::Ready, $this->store->latestFor(Northfold::ref())?->status, 'Preview changes nothing stored.');
        $this->assertSame([], array_filter($this->store->latestFor(Northfold::ref())?->decisions ?? []));
    }
}
