<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkStatus;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * What every RevisitStore must do: rows kept whole, the list's order and
 * filters, the tiles, snoozes, and who links to what. Entry IDs are the
 * test's own; a store keyed on CMS IDs maps them however it likes, as
 * long as `EntryRef::key()` round-trips.
 */
trait RevisitStoreContract
{
    abstract protected function revisitStore(): RevisitStore;

    /** The site handle or ID rows are stored under; another for the second site. */
    protected function contractSite(bool $other = false): int|string
    {
        return $other ? 'cy' : 'default';
    }

    private function row(string $id, int $score, array $kinds = [ReasonKind::PastYear], string $group = 'pages', bool $other = false, array $linksTo = [], ?string $snoozedUntil = null): RevisitRow
    {
        return new RevisitRow(
            new EntryRef($group, $id, $this->contractSite($other)),
            ucfirst($id),
            '/cp/'.$id,
            '2024-03-14T10:00:00+00:00',
            array_map(fn (ReasonKind $kind) => new RevisitReason($kind, 1, $kind === ReasonKind::PastYear ? 'New for 2024' : null), $kinds),
            $score,
            '2026-10-04T10:00:00+00:00',
            'abc123',
            ['2027-01-01'],
            $linksTo,
            ['https://example.org/gone' => new LinkResult('https://example.org/gone', LinkStatus::Broken, 404, '2026-10-01T00:00:00+00:00', 2)],
            $snoozedUntil,
        );
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-04 12:00:00');
    }

    public function test_a_row_is_kept_whole(): void
    {
        $store = $this->revisitStore();
        $row = $this->row('services', 64, [ReasonKind::PastYear, ReasonKind::BrokenLink], linksTo: ['entry::e31']);
        $store->put($row);

        $found = $store->get($row->entry);
        $this->assertNotNull($found);
        $this->assertSame($row->toArray(), $found->toArray());
        $this->assertNull($store->get(new EntryRef('pages', 'nothing', $this->contractSite())));
    }

    public function test_put_replaces_and_forget_removes(): void
    {
        $store = $this->revisitStore();
        $store->put($this->row('services', 40));
        $store->put($this->row('services', 70));

        $this->assertSame(70, $store->get(new EntryRef('pages', 'services', $this->contractSite()))?->score);
        $this->assertSame(1, $store->count($this->contractSite(), now: self::now()));

        $store->forget(new EntryRef('pages', 'services', $this->contractSite()));
        $store->forget(new EntryRef('pages', 'services', $this->contractSite()));
        $this->assertNull($store->get(new EntryRef('pages', 'services', $this->contractSite())));
    }

    public function test_top_is_by_score_with_pages_and_filters(): void
    {
        $store = $this->revisitStore();
        $store->put($this->row('about', 30, [ReasonKind::StatedCount]));
        $store->put($this->row('services', 64, [ReasonKind::PastYear, ReasonKind::BrokenLink]));
        $store->put($this->row('careers', 50, [ReasonKind::ClosingDate]));
        $store->put($this->row('autumn', 45, [ReasonKind::RelativeTime], 'journal'));
        $store->put($this->row('fine', 0, []));
        $store->put($this->row('snoozed', 90, snoozedUntil: '2027-01-01T00:00:00+00:00'));
        $store->put($this->row('welsh', 99, other: true));

        $titles = fn (array $rows) => array_map(fn (RevisitRow $row) => $row->title, $rows);

        $this->assertSame(['Services', 'Careers', 'Autumn', 'About'], $titles($store->top($this->contractSite(), now: self::now())));
        $this->assertSame(['Careers', 'Autumn'], $titles($store->top($this->contractSite(), limit: 2, offset: 1, now: self::now())));
        $this->assertSame(['Autumn'], $titles($store->top($this->contractSite(), 'journal', now: self::now())));
        $this->assertSame(['Services', 'Careers'], $titles($store->top($this->contractSite(), kinds: ['broken-link', 'closing-date'], now: self::now())));
        $this->assertSame(4, $store->count($this->contractSite(), now: self::now()));
        $this->assertSame(2, $store->count($this->contractSite(), kinds: ['broken-link', 'closing-date'], now: self::now()));
        $this->assertSame(['Welsh'], $titles($store->top($this->contractSite(true), now: self::now())));
        $this->assertSame(['Snoozed', 'Services', 'Careers', 'Autumn', 'About'], $titles($store->top($this->contractSite(), now: new DateTimeImmutable('2027-02-01'))), 'A snooze ends.');
    }

    public function test_stats_count_entries_per_reason_and_worth_a_look(): void
    {
        $store = $this->revisitStore();
        $store->put($this->row('services', 64, [ReasonKind::PastYear, ReasonKind::BrokenLink]));
        $store->put($this->row('autumn', 20, [ReasonKind::PastYear]));
        $store->put($this->row('snoozed', 90, [ReasonKind::MissingAlt], snoozedUntil: '2027-01-01T00:00:00+00:00'));
        $store->put($this->row('welsh', 99, [ReasonKind::MissingAlt], other: true));

        $stats = $store->stats($this->contractSite(), self::now());

        $this->assertSame(1, $stats['worth-a-look']);
        $this->assertSame(2, $stats['past-year']);
        $this->assertSame(1, $stats['broken-link']);
        $this->assertSame(0, $stats['missing-alt'] ?? 0);
    }

    public function test_who_links_to_a_target_on_a_site(): void
    {
        $store = $this->revisitStore();
        $store->put($this->row('services', 64, linksTo: ['entry::e31', 'entry::e12']));
        $store->put($this->row('about', 30, linksTo: ['entry::e12']));
        $store->put($this->row('welsh', 30, other: true, linksTo: ['entry::e31']));

        $keys = fn (array $refs) => array_map(fn (EntryRef $ref) => $ref->id, $refs);

        $this->assertSame(['services'], $keys($store->linkingTo('entry::e31', $this->contractSite())));
        $this->assertEqualsCanonicalizing(['services', 'about'], $keys($store->linkingTo('entry::e12', $this->contractSite())));
        $this->assertSame([], $store->linkingTo('entry::nobody', $this->contractSite()));
    }

    public function test_all_rows_of_a_site(): void
    {
        $store = $this->revisitStore();
        $store->put($this->row('services', 64));
        $store->put($this->row('fine', 0, []));
        $store->put($this->row('welsh', 30, other: true));

        $ids = fn (iterable $rows) => array_map(fn (RevisitRow $row) => (string) $row->entry->id, is_array($rows) ? $rows : iterator_to_array($rows, false));

        $this->assertEqualsCanonicalizing(['services', 'fine'], $ids($store->all($this->contractSite())));
        $this->assertEqualsCanonicalizing(['services', 'fine', 'welsh'], $ids($store->all()));
    }
}
