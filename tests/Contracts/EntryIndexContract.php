<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;

/**
 * What every EntryIndex must do. The addon saves four entries through its
 * CMS (so its own save hooks index them) and returns their references:
 *
 * - `design`, group `pages`: "Garden design", whose text holds DESIGN;
 * - `planting`, group `pages`: "Planting plans", summary "Planting plans for borders, pots and new gardens.";
 * - `journal`, group `journal`: "A walled garden in Corbridge";
 * - `other`, group `pages` on another site (or tenant): "Garden design", holding DESIGN too.
 */
trait EntryIndexContract
{
    public const DESIGN = 'A full design for your garden from our team of designers: a survey, a concept, a detailed layout and construction drawings, and a planting plan for every border.';

    abstract protected function entryIndex(): EntryIndex;

    /**
     * @return array{design: EntryRef, planting: EntryRef, journal: EntryRef, other: EntryRef}
     */
    abstract protected function indexedEntries(): array;

    public function test_a_paragraph_sharing_shingles_is_found_on_the_same_site_only(): void
    {
        $entries = $this->indexedEntries();
        $page = new EntryRef('pages', 'services-contract', $entries['design']->site);
        $found = $this->entryIndex()->sharing(Shingles::of(self::DESIGN), $page);

        $this->assertNotEmpty($found);
        $this->assertSame($entries['design']->key(), $found[0]->entry->key());
        $this->assertSame('Garden design', $found[0]->title);
        $this->assertGreaterThan(0.9, Shingles::share(Shingles::of(self::DESIGN), $found[0]->shingles));
        $this->assertNotContains($entries['other']->key(), array_map(fn (IndexedParagraph $p) => $p->entry->key(), $found));
    }

    public function test_the_entry_itself_is_left_out(): void
    {
        $entries = $this->indexedEntries();
        $found = $this->entryIndex()->sharing(Shingles::of(self::DESIGN), $entries['design']);

        $this->assertNotContains($entries['design']->key(), array_map(fn (IndexedParagraph $p) => $p->entry->key(), $found));
    }

    public function test_nothing_shared_finds_nothing(): void
    {
        $entries = $this->indexedEntries();

        $this->assertSame([], $this->entryIndex()->sharing(Shingles::of('Completely unrelated words about lighthouses, ferries and the price of herring in winter.'), $entries['design']));
    }

    public function test_the_nearest_entries_put_the_same_group_first_and_leave_the_entry_out(): void
    {
        $entries = $this->indexedEntries();
        $nearest = $this->entryIndex()->nearest($entries['design'], 'Garden design and planting plans', 10);
        $keys = array_map(fn (DigestEntry $entry) => $entry->entry->key(), $nearest);

        $this->assertNotContains($entries['design']->key(), $keys);
        $this->assertNotContains($entries['other']->key(), $keys, 'Same site only.');
        $this->assertContains($entries['planting']->key(), $keys);
        $this->assertSame($entries['planting']->key(), $keys[0], 'Same group first.');
        $this->assertLessThanOrEqual(DigestEntry::SUMMARY, mb_strlen($nearest[0]->summary));
        $this->assertCount(1, $this->entryIndex()->nearest($entries['design'], 'garden', 1));
    }
}
