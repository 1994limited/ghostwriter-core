<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryPlanStore;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedIdea;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    private InMemoryPlanStore $store;

    private InMemoryLock $lock;

    private function plan(Format $format = Format::Statamic): Plan
    {
        $this->store = new InMemoryPlanStore($format);
        $this->lock = new InMemoryLock;

        return new Plan($this->store, $this->lock, $format);
    }

    /**
     * @return array<int, string>
     */
    private function titles(?string $status = null): array
    {
        return array_values(array_map(fn (Idea $idea) => $idea->title, array_filter($this->store->ideas(), fn (Idea $idea) => $status === null || $idea->status === $status)));
    }

    public function test_an_idea_added_by_hand_is_open(): void
    {
        $idea = $this->plan()->add(['title' => '  Winter hours ', 'collection' => 'pages', 'type' => '', 'notes' => 'Short.'], new DateTimeImmutable('2026-10-02'));

        $this->assertSame('Winter hours', $idea->title);
        $this->assertSame('pages', $idea->group);
        $this->assertNull($idea->kind);
        $this->assertSame(Idea::OPEN, $idea->status);
        $this->assertSame(Idea::ADDED, $idea->source);
        $this->assertSame('2026-10-02', $idea->createdAt);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $idea->id);
    }

    public function test_each_format_gives_ideas_its_own_ids_and_dates(): void
    {
        $craft = $this->plan(Format::Craft)->add(['title' => 'A', 'section' => 'news']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $craft->id);

        $filament = $this->plan(Format::Filament)->add(['title' => 'A', 'resource' => 'posts'], new DateTimeImmutable('2026-10-02T13:00:00+00:00'));
        $this->assertSame(1, $filament->id, 'Filament ideas are numbered by the table.');
        $this->assertSame('2026-10-02 13:00:00', $filament->createdAt);
        $this->assertNull($filament->toArray()['why'], 'Empty text is null in Filament’s columns.');
    }

    public function test_looking_for_ideas_runs_once_at_a_time(): void
    {
        $plan = $this->plan();
        $plan->begin();

        $this->assertSame('working', $plan->state()->status);
        $this->assertSame('suggest', $plan->state()->task);

        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('Ghostwriter is already looking for ideas.');
        $plan->begin();
    }

    public function test_suggestions_wait_for_review_and_a_new_batch_joins_them(): void
    {
        $plan = $this->plan();
        $plan->add(['title' => 'Pruning roses', 'collection' => 'journal']);
        $plan->begin();

        $added = $plan->receive([
            new SuggestedIdea('A year in the walled garden', 'journal', null, 'Why', 'Notes'),
            ['title' => 'pruning ROSES ', 'collection' => 'journal'],
        ]);

        $this->assertSame(1, $added, 'A title already on the plan is not suggested again.');
        $this->assertSame('idle', $plan->state()->status);
        $this->assertSame([['title' => 'A year in the walled garden', 'collection' => 'journal', 'type' => null, 'why' => 'Why', 'notes' => 'Notes']], $plan->state()->pending);

        // E3: closing the review keeps the batch; a second run adds to it.
        $this->assertSame(1, $plan->receive([['title' => 'A Year in the Walled Garden'], ['title' => 'Where the money goes', 'collection' => 'journal']]));
        $this->assertCount(2, $plan->state()->pending);
        $this->assertContains('plan', $this->lock->taken);
    }

    public function test_keeping_adds_the_chosen_open_and_the_rest_dismissed(): void
    {
        $plan = $this->plan();
        $plan->receive([['title' => 'One', 'collection' => 'journal'], ['title' => 'Two', 'collection' => 'journal'], ['title' => 'Three', 'collection' => 'pages']]);

        $kept = $plan->keep(['0', 2]);

        $this->assertSame(['One', 'Three'], array_map(fn (Idea $idea) => $idea->title, $kept));
        $this->assertSame(['One', 'Three'], $this->titles(Idea::OPEN));
        $this->assertSame(['Two'], $this->titles(Idea::DISMISSED));
        $this->assertSame([Idea::SUGGESTED], array_values(array_unique(array_map(fn (Idea $idea) => $idea->source, $this->store->ideas()))));
        $this->assertSame([], $plan->state()->pending);
    }

    public function test_dropping_forgets_the_batch(): void
    {
        $plan = $this->plan();
        $plan->receive([['title' => 'One', 'collection' => 'journal']]);
        $plan->drop();

        $this->assertSame([], $plan->state()->pending);
        $this->assertSame([], $this->store->ideas(), 'Not remembered as dismissed: it may come again.');
        $this->assertSame(1, $plan->receive([['title' => 'One', 'collection' => 'journal']]));
    }

    public function test_only_a_dismissed_idea_is_put_back(): void
    {
        $plan = $this->plan(Format::Filament);
        $idea = $plan->add(['title' => 'One', 'resource' => 'posts']);
        $this->assertNotNull($idea->id);

        $plan->dismiss($idea->id);
        $back = $plan->putBack($idea->id);
        $this->assertSame(Idea::OPEN, $back->status);

        $plan->start($idea->id, 4);

        try {
            $plan->putBack($idea->id);
            $this->fail('Conflict');
        } catch (Conflict $conflict) {
            $this->assertSame('Only a dismissed idea can be put back.', $conflict->getMessage());
        }

        $this->expectException(Conflict::class);
        $plan->putBack($plan->add(['title' => 'Open', 'resource' => 'posts'])->id ?? 0);
    }

    public function test_starting_a_piece_puts_the_idea_in_hand(): void
    {
        $plan = $this->plan(Format::Filament);
        $idea = $plan->add(['title' => 'One', 'resource' => 'posts']);
        $started = $plan->start($idea->id ?? 0, 4);

        $this->assertSame(Idea::DRAFTED, $started->status);
        $this->assertSame(4, $started->toArray()['session_id']);
    }

    public function test_an_idea_whose_piece_was_deleted_is_open_again(): void
    {
        $plan = $this->plan();
        $kept = $plan->add(['title' => 'Kept', 'collection' => 'journal']);
        $orphan = $plan->add(['title' => 'Orphan', 'collection' => 'journal']);
        $plan->start($kept->id ?? '', 'S1');
        $plan->start($orphan->id ?? '', 'S2');

        $ideas = $plan->ideas(fn ($session) => $session === 'S1');

        $this->assertSame([Idea::DRAFTED, Idea::OPEN], array_map(fn (Idea $idea) => $idea->status, $ideas));
        $this->assertNull($ideas[1]->session);
        $this->assertSame(Idea::OPEN, $this->store->find($orphan->id ?? '')?->status, 'Saved so.');
    }

    public function test_only_open_or_dismissed_ideas_are_cleared(): void
    {
        $plan = $this->plan();
        $plan->add(['title' => 'Open', 'collection' => 'journal']);
        $dismissed = $plan->add(['title' => 'Dismissed', 'collection' => 'journal']);
        $started = $plan->add(['title' => 'Started', 'collection' => 'journal']);
        $plan->dismiss($dismissed->id ?? '');
        $plan->start($started->id ?? '', 'S1');

        $this->assertSame(1, $plan->clear(Idea::OPEN));
        $this->assertSame(['Dismissed', 'Started'], $this->titles());

        $this->expectException(Conflict::class);
        $plan->clear(Idea::DRAFTED);
    }

    public function test_open_ideas_are_grouped_newest_first(): void
    {
        $plan = $this->plan();

        foreach ([['A', 'journal'], ['B', 'pages'], ['C', 'journal']] as [$title, $group]) {
            $plan->add(['title' => $title, 'collection' => $group]);
        }

        $groups = Plan::openByGroup($this->store->ideas());

        $this->assertSame(['journal', 'pages'], array_keys($groups));
        $this->assertSame(['C', 'A'], array_map(fn (Idea $idea) => $idea->title, $groups['journal']));
    }

    public function test_an_ideas_words_can_be_changed(): void
    {
        $plan = $this->plan(Format::Craft);
        $idea = $plan->add(['title' => 'One', 'section' => 'news']);
        $edited = $plan->edit($idea->id ?? '', ['title' => ' Two ', 'section' => 'blog', 'type' => 'guide', 'status' => 'dismissed']);

        $this->assertSame('Two', $edited->title);
        $this->assertSame('blog', $edited->group);
        $this->assertSame('guide', $edited->kind);
        $this->assertSame(Idea::OPEN, $edited->status, 'The state changes only through the rules.');
        $this->assertTrue(Plan::isDuplicate('two', $this->store->ideas()));
        $this->assertFalse(Plan::isDuplicate('three', $this->store->ideas()));
    }

    public function test_a_missing_idea_is_not_found(): void
    {
        $this->expectException(NotFound::class);
        $this->plan()->dismiss('nothing');
    }

    public function test_a_failure_is_shown_once(): void
    {
        $plan = $this->plan();
        $plan->begin();
        $plan->failed('Overloaded.');

        $state = $plan->state();
        $this->assertSame('failed', $state->status);
        $this->assertTrue($state->forgetFailure());
        $this->assertSame('idle', $state->status);
        $this->assertNull($state->error);
    }
}
