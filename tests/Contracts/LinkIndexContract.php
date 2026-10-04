<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkLookup;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;

/**
 * What every link index must do (SEO layer §7.1, §20): link rows for
 * routable pages outside Ghostwriter's own groups, written when they're
 * saved and forgotten when they're deleted; related() returns them, while
 * sharing() and nearest() (Overlaps, the review's digest) and Content to
 * revisit don't see them.
 *
 * The addon saves these pages through its CMS (so its own hooks index
 * them) and returns their references from linkEntries():
 *
 * - `design`: a published page of one of Ghostwriter's groups, "Garden design", holding DESIGN;
 * - `about`: a published page of a group Ghostwriter doesn't write for, "About our garden design studio", holding ABOUT;
 * - `contact`: a published page of such a group, "Contact us", summary "Book a garden design consultation…";
 * - `draft`: an unpublished page of such a group, "Garden design draft notes";
 * - `scheduled`: a page of such a group going live on scheduledFrom(), "Garden design open day";
 * - `noindex`: a published page of such a group that an SEO addon or field marks noindex, "Garden design offer";
 * - `search`: a published page of such a group at `/search`, "Search garden design";
 * - `home`: the home page, "Garden design studio" (null where the CMS has none);
 * - `other`: `about`'s page on another site or tenant (null where there's one).
 *
 * The draft is a new page of draftGroup() on `design`'s site.
 */
trait LinkIndexContract
{
    public const DRAFT = "Planning a new garden design\n\n## What a garden design includes\n\nA garden design starts with a visit from our studio: we survey the garden, talk about how you use it and sketch a concept. Contact us to book a consultation.\n\n## Planting\n\nEvery design comes with a planting plan for each border.";

    public const ABOUT = 'Our studio has designed gardens across the north for twenty years, from small courtyards to walled gardens and estates.';

    abstract protected function linkIndex(): LinkIndex;

    abstract protected function entryIndex(): EntryIndex;

    /**
     * @return array{design: EntryRef, about: EntryRef, contact: EntryRef, draft: EntryRef, scheduled: EntryRef, noindex: EntryRef, search: EntryRef, home: ?EntryRef, other: ?EntryRef}
     */
    abstract protected function linkEntries(): array;

    /** The group the draft will be in: `design`'s. */
    abstract protected function draftGroup(): string;

    /** The day `scheduled` goes live. */
    abstract protected function scheduledFrom(): DateTimeImmutable;

    /** Saves the page again through the CMS with a new title. */
    abstract protected function retitle(EntryRef $entry, string $title): void;

    /** Deletes the page through the CMS. */
    abstract protected function deleteEntry(EntryRef $entry): void;

    /** Whether Content to revisit has a row for the page. */
    abstract protected function hasRevisitRow(EntryRef $entry): bool;

    public function test_a_page_outside_ghostwriters_groups_is_a_candidate(): void
    {
        $entries = $this->linkEntries();
        $keys = $this->relatedKeys();

        $this->assertContains($entries['about']->key(), $keys);
        $this->assertContains($entries['contact']->key(), $keys);
        $this->assertContains($entries['design']->key(), $keys, 'Full rows are candidates too.');
    }

    public function test_drafts_scheduled_noindex_utility_and_home_pages_are_left_out(): void
    {
        $entries = $this->linkEntries();
        $keys = $this->relatedKeys();

        foreach (['draft', 'scheduled', 'noindex', 'search', 'home'] as $name) {
            if ($entries[$name] !== null) {
                $this->assertNotContains($entries[$name]->key(), $keys, "The {$name} page is left out.");
            }
        }
    }

    public function test_a_scheduled_page_is_a_candidate_from_its_day_without_a_save(): void
    {
        $entries = $this->linkEntries();

        $this->assertContains($entries['scheduled']->key(), $this->relatedKeys(now: $this->scheduledFrom()->modify('+1 day')));
    }

    public function test_the_page_itself_and_pages_already_linked_are_left_out(): void
    {
        $entries = $this->linkEntries();
        $about = $this->candidate($entries['about']);

        $this->assertNotNull($about);
        $this->assertNotContains($entries['about']->key(), $this->relatedKeys(except: $entries['about']));
        $this->assertNotContains($entries['about']->key(), $this->relatedKeys(linked: [is_string($about->link) ? $about->link : (string) $about->url]));
    }

    public function test_never_another_sites_pages(): void
    {
        $entries = $this->linkEntries();

        if ($entries['other'] === null) {
            $this->markTestSkipped('One site only.');
        }

        $this->assertNotContains($entries['other']->key(), $this->relatedKeys());
    }

    public function test_a_candidate_says_what_it_is_and_how_to_link_to_it(): void
    {
        $about = $this->candidate($this->linkEntries()['about']);

        $this->assertNotNull($about);
        $this->assertSame('About our garden design studio', $about->title);
        $this->assertNotSame('', $about->type, 'The group\'s label.');
        $this->assertNotNull($about->url);
        $this->assertNotNull($about->link);
        $this->assertLessThanOrEqual(DigestEntry::SUMMARY, mb_strlen($about->summary));
        $this->assertLessThanOrEqual(25, count($this->linkIndex()->related(self::DRAFT, $this->draftGroup(), $this->linkEntries()['design']->site)));
    }

    public function test_link_rows_stay_out_of_overlaps_the_digest_and_content_to_revisit(): void
    {
        $entries = $this->linkEntries();
        $shared = $this->entryIndex()->sharing(Shingles::of(self::ABOUT), $entries['design']);
        $nearest = array_map(fn (DigestEntry $entry) => $entry->entry?->key(), $this->entryIndex()->nearest($entries['design'], 'About our garden design studio. Contact us.', 20));

        $this->assertNotContains($entries['about']->key(), array_map(fn (IndexedParagraph $p) => $p->entry->key(), $shared));
        $this->assertNotContains($entries['about']->key(), $nearest);
        $this->assertNotContains($entries['contact']->key(), $nearest);
        $this->assertFalse($this->hasRevisitRow($entries['about']));
        $this->assertFalse($this->hasRevisitRow($entries['contact']));
    }

    public function test_a_save_writes_the_row_again(): void
    {
        $entries = $this->linkEntries();
        $this->retitle($entries['about'], 'About our garden design practice');

        $this->assertSame('About our garden design practice', $this->candidate($entries['about'])?->title);
    }

    public function test_a_delete_forgets_the_row(): void
    {
        $entries = $this->linkEntries();
        $this->deleteEntry($entries['about']);

        $this->assertNotContains($entries['about']->key(), $this->relatedKeys());
    }

    public function test_a_link_already_in_a_draft_finds_its_page(): void
    {
        $index = $this->linkIndex();

        if (! $index instanceof LinkLookup) {
            $this->markTestSkipped('This index can\'t look links up (LinkLookup).');
        }

        $entries = $this->linkEntries();
        $site = $entries['design']->site;
        $about = $this->candidate($entries['about']);
        $design = $this->candidate($entries['design']);

        $this->assertNotNull($about);
        $this->assertNotNull($design);
        $this->assertTrue($index->linkRow(is_string($about->link) ? $about->link : (string) $about->url, $site)?->entry->is($entries['about']), 'By what a link to it stores.');
        $this->assertTrue($index->linkRow((string) $about->url, $site)?->entry->is($entries['about']), 'By its address.');
        $this->assertTrue($index->linkRow(is_string($design->link) ? $design->link : (string) $design->url, $site)?->entry->is($entries['design']), 'Full rows too.');
        $this->assertNull($index->linkRow('/no-such-page-anywhere', $site));
        $this->assertNull($index->linkRow('https://elsewhere.example.org'.IndexRow::pathOf($about->url), $site), 'Another site\'s address with the same path.');
    }

    /**
     * @param  list<string>  $linked
     * @return list<string>
     */
    private function relatedKeys(?EntryRef $except = null, array $linked = [], ?DateTimeImmutable $now = null): array
    {
        $site = $this->linkEntries()['design']->site;

        return array_values(array_filter(array_map(
            fn (DigestEntry $entry) => $entry->entry?->key(),
            $this->linkIndex()->related(self::DRAFT, $this->draftGroup(), $site, $except, 25, $linked, $now),
        )));
    }

    private function candidate(EntryRef $entry): ?DigestEntry
    {
        foreach ($this->linkIndex()->related(self::DRAFT, $this->draftGroup(), $this->linkEntries()['design']->site) as $candidate) {
            if ($candidate->entry?->is($entry)) {
                return $candidate;
            }
        }

        return null;
    }
}
