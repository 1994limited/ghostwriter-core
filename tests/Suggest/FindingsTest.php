<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quiet;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use PHPUnit\Framework\TestCase;

/**
 * The free half of the mockup's Services page: five of its seven
 * suggestions are found with no model, each anchored with a quote the
 * field still has.
 */
final class FindingsTest extends TestCase
{
    public function test_the_services_page_has_the_mockups_free_findings_in_form_order(): void
    {
        $findings = Findings::standard()->find(Northfold::context());

        $this->assertSame([
            'out-of-date|page_builder/#h1/eyebrow|new for 2024 winter care visits|0',
            'fact-to-check|page_builder/#t1/text|team of 6|0',
            'clarity|page_builder/#t1/text|in terms of the actual process involved what typically happens is that we will first of all come out and visit the garden in person after which we will then go away and produce a concept|0',
            'link|page_builder/#t1/text|our 2023 show garden|0',
            'accessibility|page_builder/#i1/image|assets::materials.jpg|0',
            'seo|seo_description||0',
        ], array_map(fn (Finding $finding) => $finding->id, $findings));

        [$year, $count, $long, $link, $alt, $seo] = $findings;

        $this->assertSame(Needs::Words, $year->needs);
        $this->assertSame('Says “New for 2024” in 2026.', $year->message->english());
        $this->assertSame(Needs::Editor, $count->needs);
        $this->assertSame('A count from 2024-03: “team of 6”. Is it still right?', $count->message->english());
        $this->assertFalse($long->alone, 'A long sentence is only a hint.');
        $this->assertSame('entry::e12', $link->meta['candidates'][0]['value']);
        $this->assertSame(AnchorScope::Asset, $alt->anchor->scope);
        $this->assertSame('materials.jpg has no alt text.', $alt->message->english());
        $this->assertSame(AnchorScope::Field, $seo->anchor->scope);
        $this->assertSame('SEO description is 195 characters; the limit is 160.', $seo->message->english());
    }

    public function test_every_range_is_found_again_in_its_field(): void
    {
        $context = Northfold::context();

        foreach (Findings::standard()->find($context) as $finding) {
            if ($finding->anchor->scope !== AnchorScope::Range) {
                continue;
            }

            $text = $this->valueAt($context, $finding->anchor->path->toString());
            $this->assertNotNull($finding->anchor->quote);
            $this->assertNotNull((new QuoteFinder)->find($finding->anchor->quote, $text, $finding->anchor->occurrence, markdown: true), $finding->id);
        }
    }

    public function test_a_repeated_quote_keeps_its_occurrence(): void
    {
        $context = FreeChecksTest::context('New for 2024: visits. Book now. New for 2024: visits.');
        $findings = array_values(array_filter(Findings::standard()->find($context), fn (Finding $f) => $f->kind === 'past-year'));

        $this->assertSame([0, 1], array_map(fn (Finding $f) => $f->anchor->occurrence, $findings));
        $this->assertNotSame($findings[0]->id, $findings[1]->id);
    }

    public function test_its_still_right_lasts_twelve_months_or_until_the_text_changes(): void
    {
        $count = $this->find('stated-count', Northfold::context());
        $quiet = Quiet::of($count->id, $count->anchor, new DateTimeImmutable(Northfold::NOW), Quiet::CONFIRMED, 7);
        $quieted = new Quieted([$quiet]);

        $this->assertNull($this->find('stated-count', Northfold::context(quieted: $quieted)), 'Confirmed: quiet.');
        $this->assertNull($this->find('stated-count', Northfold::context(quieted: $quieted, now: '2027-09-30')), 'Still quiet within 12 months.');
        $this->assertNotNull($this->find('stated-count', Northfold::context(quieted: $quieted, now: '2027-10-05')), 'Back after 12 months.');

        $edited = Northfold::entry(['page_builder' => [
            ['id' => 't1', 'type' => 'text', 'text' => str_replace('a survey, a concept', 'a survey and a concept', Northfold::TEXT)],
        ]]);
        $this->assertNotNull($this->find('stated-count', Northfold::context(entry: $edited, quieted: $quieted)), 'Back once its sentence is edited.');

        $elsewhere = Northfold::entry(['page_builder' => [
            ['id' => 't1', 'type' => 'text', 'text' => Northfold::TEXT."\n\nA new paragraph."],
        ]]);
        $this->assertNull($this->find('stated-count', Northfold::context(entry: $elsewhere, quieted: $quieted)), 'An edit elsewhere in the field changes nothing.');
    }

    public function test_a_dismissal_sticks_the_same_way(): void
    {
        $year = $this->find('past-year', Northfold::context());
        $quieted = (new Quieted)->with(Quiet::of($year->id, $year->anchor, new DateTimeImmutable(Northfold::NOW)));

        $this->assertNull($this->find('past-year', Northfold::context(quieted: $quieted)));
        $this->assertSame(Quieted::fromArray($quieted->toArray())->toArray(), $quieted->toArray(), 'Round trip.');
    }

    public function test_overlaps_with_another_entry(): void
    {
        $index = (new MemoryEntryIndex)
            ->add(new EntryRef('pages', 'design', 'default'), 'Garden design', ['A full design for your garden from our team of designers: a survey, a concept, a detailed layout and construction drawings, and a planting plan. In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept for you.'], '/garden-design')
            ->add(new EntryRef('pages', 'other-site', 'cy'), 'Elsewhere', [Northfold::TEXT]);

        $overlap = $this->find('overlap', Northfold::context(index: $index));

        $this->assertNotNull($overlap);
        $this->assertSame(Category::Duplicate, $overlap->category);
        $this->assertSame('pages:design@default', $overlap->meta['entry']);
        $this->assertStringStartsWith('A full design for your garden', $overlap->anchor->quote->exact ?? '');
        $this->assertNull($this->find('overlap', Northfold::context()), 'No index, no overlaps.');
        $this->assertNull($this->find('overlap', Northfold::context(index: new MemoryEntryIndex)));
    }

    public function test_a_passed_date_field_is_a_fact_to_check(): void
    {
        $schema = new Schema([new Field('title', Kind::Text), new Field('closing_date', Kind::Reference, 'Closing date', type: 'date'), new Field('published_at', Kind::Reference, 'Published', type: 'date')]);
        $context = new CheckContext(
            gaps: new GapContext(schema: $schema, entry: new EntryData(['title' => 'Careers', 'closing_date' => '2025-01-31', 'published_at' => '2020-01-01'])),
            now: new DateTimeImmutable(Northfold::NOW),
        );

        $findings = Findings::standard()->find($context);

        $this->assertSame(['fact-to-check|closing_date||0'], array_map(fn (Finding $f) => $f->id, $findings));
        $this->assertSame('Closing date (2025-01-31) has passed.', $findings[0]->message->english());
    }

    public function test_without_ports_the_gap_findings_are_not_made(): void
    {
        $context = new CheckContext(gaps: new GapContext(schema: Northfold::schema(), entry: Northfold::entry()), now: new DateTimeImmutable(Northfold::NOW), updatedAt: new DateTimeImmutable(Northfold::UPDATED));
        $kinds = array_map(fn (Finding $f) => $f->kind, Findings::standard()->find($context));

        $this->assertSame(['past-year', 'stated-count', 'long-sentence', 'long-sentence'], $kinds, 'With no SeoFields, the SEO description is prose like any other.');
    }

    public function test_a_finding_round_trips(): void
    {
        foreach (Findings::standard()->find(Northfold::context()) as $finding) {
            $this->assertEquals($finding->toArray(), Finding::fromArray(json_decode((string) json_encode($finding->toArray()), true))->toArray());
        }
    }

    public function test_the_revisit_scan_can_leave_overlaps_out(): void
    {
        $kinds = array_merge(...array_map(fn ($check) => $check->kinds(), Findings::standard()->without('overlap')->checks()));

        $this->assertNotContains('overlap', $kinds);
        $this->assertContains('past-year', $kinds);
    }

    private function find(string $kind, CheckContext $context): ?Finding
    {
        foreach (Findings::standard()->find($context) as $finding) {
            if ($finding->kind === $kind) {
                return $finding;
            }
        }

        return null;
    }

    private function valueAt(CheckContext $context, string $path): string
    {
        foreach (Walk::entry($context->gaps->schema, $context->gaps->entry) as $visit) {
            if ($visit->path->toString() === $path) {
                return (string) Walk::text($visit, $context->gaps->richText);
            }
        }

        $this->fail("Nothing at {$path}.");
    }
}
