<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Northfold;
use PHPUnit\Framework\TestCase;

/** The mockup's revisit rows, scored with no model. */
final class RevisitScannerTest extends TestCase
{
    public function test_the_services_page_gets_the_mockups_chips(): void
    {
        $context = Northfold::context();
        $row = (new RevisitScanner)->scan(new EntrySnapshot(Northfold::ref(), 'Services', '/cp/services', $context), new DateTimeImmutable(Northfold::NOW));

        $this->assertSame(['broken-link', 'past-year', 'stated-count', 'missing-alt', 'seo-length', 'age'], array_map(fn (RevisitReason $r) => $r->kind->value, $row->reasons));
        $this->assertSame('“New for 2024”', $row->reasons[1]->message()->english());
        $this->assertSame('1 broken link', $row->reasons[0]->message()->english());
        $this->assertSame('no alt text', $row->reasons[3]->message()->english());
        $this->assertSame('2 years old', $row->reasons[5]->message()->english());
        $this->assertSame('high', $row->reasons[1]->severity());
        $this->assertGreaterThanOrEqual(Priority::WORTH_A_LOOK, $row->score);
        $this->assertSame('high', $row->priority());
        $this->assertContains('entry::e31', $row->linksTo);
    }

    public function test_a_journal_post_saying_this_year(): void
    {
        $ref = new EntryRef('journal', 'autumn', 'default');
        $scan = fn (?AgePolicy $age) => (new RevisitScanner($age ?? new AgePolicy))->scan(Fixtures::snapshot($ref, 'What to do in the garden in late autumn', '2023-11-02', 'This year the leaves fell late.', age: $age), new DateTimeImmutable(Fixtures::NOW));

        $plain = $scan(null);
        $dated = $scan(AgePolicy::fromGroups(['journal']));

        $this->assertSame('“This year” = 2023', $plain->reasons[0]->message()->english());
        $this->assertSame(ReasonKind::Age, $plain->reasons[1]->kind);
        $this->assertSame(34, $plain->score);
        $this->assertSame(9, $dated->score, 'A quarter weight in a dated group.');
    }

    public function test_rescoring_with_nothing_changed_equals_a_full_scan(): void
    {
        $scanner = new RevisitScanner;
        $snapshot = new EntrySnapshot(Northfold::ref(), 'Services', '/cp/services', Northfold::context());
        $row = $scanner->scan($snapshot, new DateTimeImmutable(Northfold::NOW));

        $this->assertEquals($row->toArray(), $scanner->rescore($row, new DateTimeImmutable(Northfold::NOW))->toArray());

        $later = new DateTimeImmutable('2026-12-04 10:00:00');
        $rescored = $scanner->rescore($row, $later);
        $rescanned = $scanner->scan(new EntrySnapshot(Northfold::ref(), 'Services', '/cp/services', Northfold::context(now: '2026-12-04 10:00:00')), $later);

        $this->assertSame($rescanned->score, $rescored->score, 'Two months on, re-scoring gives what a scan gives.');
        $this->assertEquals(array_map(fn ($r) => $r->toArray(), $rescanned->reasons), array_map(fn ($r) => $r->toArray(), $rescored->reasons));
    }

    public function test_watch_dates_for_a_closing_date_and_this_years_phrase(): void
    {
        $snapshot = Fixtures::snapshot(new EntryRef('pages', 'careers', 'default'), 'Careers', '2026-09-01', 'Applications close 30 November 2026. New for 2026: apprenticeships.');
        $row = (new RevisitScanner)->scan($snapshot, new DateTimeImmutable(Fixtures::NOW));

        $this->assertSame(['2026-12-01', '2027-01-01', '2027-09-01'], $row->watch);
        $this->assertSame([], $row->reasons, 'Nothing yet.');
    }

    public function test_scanning_calls_no_model(): void
    {
        $fake = new FakeProvider;
        (new RevisitScanner)->scan(new EntrySnapshot(Northfold::ref(), 'Services', null, Northfold::context()), new DateTimeImmutable(Northfold::NOW));

        $fake->assertNothingSent();
    }
}
