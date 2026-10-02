<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;

/**
 * What every KindStore must do. See SessionStoreContract for how an addon
 * runs it.
 */
trait KindStoreContract
{
    abstract protected function kindStore(): KindStore;

    abstract protected function storeFormat(): Format;

    private function kind(string $handle, string $title): ContentType
    {
        return new ContentType(
            $this->storeFormat(),
            $handle,
            $title,
            'A seasonal how-to.',
            'journal',
            [['handle' => 'subject', 'label' => 'What is it about?', 'type' => 'textarea', 'required' => true]],
            "Open on the moment in the year.\n\nKeep steps short.",
            ['Says when to plant.'],
            examples: $this->storeFormat() === Format::Statamic ? ['946d6d06-9812-459f-8a18-08fd0382a421'] : [109],
        );
    }

    public function test_a_saved_kind_is_found_as_it_was(): void
    {
        $store = $this->kindStore();
        $store->save($this->kind('planting-guide', 'Planting guide'));

        $found = $store->find('planting-guide');

        $this->assertNotNull($found);
        $this->assertSame('Planting guide', $found->title);
        $this->assertSame('journal', $found->group);
        $this->assertSame("Open on the moment in the year.\n\nKeep steps short.", $found->guidance);
        $this->assertSame(['Says when to plant.'], $found->checklist);
        $this->assertSame('subject', $found->questions[0]['handle']);
        $this->assertSame(array_map('strval', $this->kind('x', 'X')->examples), array_map('strval', $found->examples));
        $this->assertNull($store->find('nothing-here'));
    }

    public function test_kinds_are_listed_by_title_and_saved_over_by_handle(): void
    {
        $store = $this->kindStore();
        $store->save($this->kind('b', 'Planting guide'));
        $store->save($this->kind('a', 'Case study'));
        $store->save($this->kind('b', 'Pruning guide'));

        $this->assertSame(['Case study', 'Pruning guide'], array_map(fn (ContentType $type) => $type->title, $store->all()));
    }

    public function test_a_deleted_kind_is_gone(): void
    {
        $store = $this->kindStore();
        $store->save($this->kind('a', 'Case study'));
        $store->delete('a');
        $store->delete('a');

        $this->assertNull($store->find('a'));
        $this->assertSame([], $store->all());
    }

    public function test_suggestions_are_kept_per_group(): void
    {
        $store = $this->kindStore();

        $this->assertSame([], $store->suggestions('journal')->suggestions);
        $this->assertNull($store->suggestions('journal')->checkedAt);

        $suggestions = $store->suggestions('journal');
        $suggestions->store([['title' => 'Site page', 'description' => 'A page.', 'why' => 'All six are.', 'examples' => ['1', '2']]], 6);
        $suggestions->remove('nothing', true);
        $store->saveSuggestions('journal', $suggestions);

        $found = $store->suggestions('journal');
        $this->assertSame($suggestions->suggestions, $found->suggestions);
        $this->assertSame(6, $found->records);
        $this->assertNotNull($found->checkedAt);
        $this->assertSame([], $store->suggestions('pages')->suggestions);
    }

    public function test_analysis_is_idle_until_saved_and_kept_per_group(): void
    {
        $store = $this->kindStore();

        $this->assertSame('idle', $store->analysis('journal')->status);

        $store->saveAnalysis('journal', new Analysis('failed', 'It stopped.'));

        $this->assertSame(['status' => 'failed', 'error' => 'It stopped.'], $store->analysis('journal')->toArray());
        $this->assertSame('idle', $store->analysis('pages')->status);
    }
}
