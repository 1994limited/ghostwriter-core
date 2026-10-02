<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;

/**
 * What every PlanStore must do. See SessionStoreContract for how an addon
 * runs it.
 */
trait PlanStoreContract
{
    abstract protected function planStore(): PlanStore;

    abstract protected function storeFormat(): Format;

    private function idea(string $title): Idea
    {
        return Idea::make($this->storeFormat(), ['title' => $title, $this->storeFormat()->groupKey() => 'journal', 'why' => 'Nothing covers it.', 'notes' => 'Keep it short.']);
    }

    public function test_a_saved_idea_is_given_an_id_and_found(): void
    {
        $store = $this->planStore();
        $idea = $this->idea('Pruning roses in February');
        $idea->id = null;

        $saved = $store->save($idea);

        $this->assertNotNull($saved->id);
        $found = $store->find($saved->id);
        $this->assertNotNull($found);
        $this->assertSame('Pruning roses in February', $found->title);
        $this->assertSame('journal', $found->group);
        $this->assertSame('Nothing covers it.', $found->why);
        $this->assertSame('Keep it short.', $found->notes);
        $this->assertSame(Idea::OPEN, $found->status);
        $this->assertSame(Idea::ADDED, $found->source);
        $this->assertNull($found->session);
    }

    public function test_ideas_are_listed_in_the_order_they_were_added(): void
    {
        $store = $this->planStore();

        foreach (['One', 'Two', 'Three'] as $title) {
            $store->save($this->idea($title));
        }

        $this->assertSame(['One', 'Two', 'Three'], array_map(fn (Idea $idea) => $idea->title, $store->ideas()));
    }

    public function test_a_change_is_saved_over_the_idea(): void
    {
        $store = $this->planStore();
        $idea = $store->save($this->idea('Pruning roses'));
        $this->assertNotNull($idea->id);

        $found = $store->find($idea->id);
        $this->assertNotNull($found);
        $found->status = Idea::DRAFTED;
        $found->session = $this->storeFormat() === Format::Filament ? 7 : $this->storeFormat()->newId();
        $store->save($found);

        $again = $store->find($idea->id);
        $this->assertNotNull($again);
        $this->assertSame(Idea::DRAFTED, $again->status);
        $this->assertSame((string) $found->session, (string) $again->session);
        $this->assertCount(1, $store->ideas());
    }

    public function test_a_deleted_idea_is_gone(): void
    {
        $store = $this->planStore();
        $kept = $store->save($this->idea('Kept'));
        $gone = $store->save($this->idea('Gone'));
        $this->assertNotNull($gone->id);

        $store->delete($gone->id);
        $store->delete($gone->id);

        $this->assertNull($store->find($gone->id));
        $this->assertSame(['Kept'], array_map(fn (Idea $idea) => $idea->title, $store->ideas()));
        $this->assertNotNull($kept->id);
    }

    public function test_the_plan_state_is_idle_until_saved_and_then_kept(): void
    {
        $store = $this->planStore();
        $state = $store->state();

        $this->assertSame('idle', $state->status);
        $this->assertSame([], $state->pending);

        $state->begin('suggest');
        $state->pending = [['title' => 'A year in the walled garden', $this->storeFormat()->groupKey() => 'journal', 'why' => '', 'notes' => '']];
        $store->saveState($state);

        $again = $store->state();
        $this->assertSame('working', $again->status);
        $this->assertSame($state->pending, $again->pending);
    }
}
