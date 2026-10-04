<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitIndex;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\InMemoryRevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\MemoryEntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use PHPUnit\Framework\TestCase;

/** Keeping the list current: on save, on delete, daily and weekly. */
final class RevisitIndexTest extends TestCase
{
    private MemoryEntrySource $source;

    private InMemoryRevisitStore $store;

    private RevisitIndex $index;

    protected function setUp(): void
    {
        $this->source = (new MemoryEntrySource)
            ->put(Fixtures::snapshot(self::ref('services'), 'Services', '2024-03-14', 'New for 2024: visits. See [the garden](entry::e12).'))
            ->put(Fixtures::snapshot(self::ref('about'), 'About', '2026-09-01', 'We are gardeners.'))
            ->put(Fixtures::snapshot(self::ref('careers'), 'Careers', '2026-09-01', 'Applications close 30 November 2026.'))
            ->put(Fixtures::snapshot(self::ref('draft'), 'Draft', '2026-09-01', 'New for 2020.', published: false));
        $this->store = new InMemoryRevisitStore;
        $this->index = new RevisitIndex(new RevisitScanner, $this->store);
    }

    private static function ref(string $id): EntryRef
    {
        return new EntryRef('pages', $id, 'default');
    }

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function test_the_first_run_scans_every_published_entry(): void
    {
        $this->assertSame(3, $this->index->refresh($this->source, self::at(Fixtures::NOW)));
        $this->assertNull($this->store->get(self::ref('draft')));
        $this->assertSame(['Services'], array_map(fn (RevisitRow $row) => $row->title, $this->store->top('default', now: self::at(Fixtures::NOW))));
    }

    public function test_a_save_scans_one_entry_and_an_unpublished_one_is_forgotten(): void
    {
        $this->index->refresh($this->source, self::at(Fixtures::NOW));
        $this->source->put(Fixtures::snapshot(self::ref('about'), 'About', '2026-10-04', 'New for 2025: a shop.'));

        $row = $this->index->refreshOne($this->source, self::ref('about'), self::at(Fixtures::NOW));
        $this->assertTrue($row?->has(ReasonKind::PastYear));

        $this->source->put(Fixtures::snapshot(self::ref('about'), 'About', '2026-10-04', 'New for 2025.', published: false));
        $this->index->refreshOne($this->source, self::ref('about'), self::at(Fixtures::NOW));
        $this->assertNull($this->store->get(self::ref('about')));
    }

    public function test_deleting_a_linked_entry_rescans_its_linkers_at_once(): void
    {
        $this->index->refresh($this->source, self::at(Fixtures::NOW));
        $this->assertFalse($this->store->get(self::ref('services'))?->has(ReasonKind::BrokenLink));

        // The garden entry is deleted: LinkTargets no longer knows it.
        $this->source->put(Fixtures::snapshot(self::ref('services'), 'Services', '2024-03-14', 'New for 2024: visits. See [the garden](entry::e12).', targets: []));
        $again = $this->index->deleted($this->source, new EntryRef('journal', 'e12', 'default'), self::at(Fixtures::NOW), ['entry::e12']);

        $this->assertSame(['pages:services@default'], array_map(fn (EntryRef $ref) => $ref->key(), $again));
        $this->assertSame('1 broken link', $this->store->get(self::ref('services'))?->reasons[0]->message()->english());
    }

    public function test_the_daily_pass_rescans_saved_and_watched_entries_and_rescores_the_rest(): void
    {
        $this->index->refresh($this->source, self::at(Fixtures::NOW));
        $this->assertFalse($this->store->get(self::ref('careers'))?->has(ReasonKind::ClosingDate));

        $scanned = $this->index->refresh($this->source, self::at('2026-12-01 03:00'), self::at('2026-11-30 03:00'));

        $this->assertSame(1, $scanned, 'Only Careers, whose closing date passed.');
        $this->assertTrue($this->store->get(self::ref('careers'))?->has(ReasonKind::ClosingDate));
        $this->assertSame(['2026-12-01T03:00:00+00:00', '2026-10-04T10:00:00+00:00'], [$this->store->get(self::ref('careers'))?->checkedAt, $this->store->get(self::ref('services'))?->checkedAt], 'Services was only re-scored.');
    }

    public function test_a_snooze_survives_a_scan(): void
    {
        $this->index->refresh($this->source, self::at(Fixtures::NOW));
        $this->store->put($this->store->get(self::ref('services'))->snooze(self::at(Fixtures::NOW)));
        $this->index->refreshOne($this->source, self::ref('services'), self::at(Fixtures::NOW));

        $this->assertSame([], $this->store->top('default', now: self::at(Fixtures::NOW)));
        $this->assertSame('2027-01-02T10:00:00+00:00', $this->store->get(self::ref('services'))?->snoozedUntil);
    }
}
