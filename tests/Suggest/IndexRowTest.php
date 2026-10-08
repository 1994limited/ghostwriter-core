<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SiteDigest;
use PHPUnit\Framework\TestCase;

final class IndexRowTest extends TestCase
{
    public function test_make_trims_the_summary_and_works_out_stems_per_part(): void
    {
        $row = IndexRow::make(new EntryRef('pages', 'contact', 'default'), IndexScope::Link, ' Contact  us ', '/about/get-in-touch', str_repeat('Book a visit. ', 30), 'Pages', locale: 'en');

        $this->assertSame('Contact us', $row->title);
        $this->assertLessThanOrEqual(DigestEntry::SUMMARY, mb_strlen($row->summary));
        $this->assertSame(['contact'], $row->stemsOf('title'));
        $this->assertSame(IndexRow::STEMS, $row->stemsVersion());
        $this->assertSame(['get', 'touch'], $row->stemsOf('slug'), '"in" is a stop word.');
        $this->assertSame(['book', 'visit'], $row->stemsOf('summary'));
        $this->assertSame('get-in-touch', $row->slug());
        $this->assertSame('/about/get-in-touch', $row->path());
    }

    public function test_it_round_trips_and_reads_rows_written_before_link_rows(): void
    {
        $row = IndexRow::make(new EntryRef('categories', 5, 1), IndexScope::Link, 'Shrubs', '/plants/shrubs', 'Shrubs for every garden', 'Plant categories', RowKind::Category, '2026-10-10', null, true, true, '{category:5@1:url}', '2026-09-01', true, '2026-10-04', 'en');

        $this->assertEquals($row, IndexRow::fromArray($row->toArray()));

        $old = IndexRow::fromArray(['entry' => ['group' => 'pages', 'id' => 'a', 'site' => 'default'], 'title' => 'Garden design', 'url' => '/garden-design', 'summary' => '', 'paragraphs' => [[1, 2]]]);
        $this->assertNotNull($old);
        $this->assertSame(IndexScope::Full, $old->scope);
        $this->assertSame(['garde', 'desig'], $old->stemsOf('title'), 'No language: the first five letters.');
        $this->assertNull(IndexRow::fromArray(['title' => 'No entry']));
    }

    public function test_rows_stemmed_before_the_stemmer_keep_their_stems_and_say_so(): void
    {
        $stored = ['entry' => ['group' => 'journal', 'id' => 'a', 'site' => 'default'], 'scope' => 'link', 'title' => 'Why we leave the seedheads standing', 'url' => '/journal/seedheads', 'stems' => ['title' => ['leave', 'seedh', 'stand'], 'slug' => ['seedh'], 'summary' => []]];
        $old = IndexRow::fromArray($stored, 'en');

        $this->assertNotNull($old);
        $this->assertSame(1, $old->stemsVersion(), 'Matched by their start until a full refresh writes them again.');
        $this->assertSame(['leave', 'seedh', 'stand'], $old->stemsOf('title'));
        $this->assertArrayNotHasKey('v', $old->toArray()['stems']);

        $new = IndexRow::fromArray([...$stored, 'stems' => null], 'en');
        $this->assertSame(['leav', 'seedhead', 'stand'], $new?->stemsOf('title'), 'A row with no stems is stemmed again.');
        $this->assertSame(IndexRow::STEMS, $new?->toArray()['stems']['v']);
        $this->assertSame(IndexRow::STEMS, IndexRow::fromArray((array) $new?->toArray())?->stemsVersion());
    }

    public function test_terms_and_links_are_kept_and_round_trip(): void
    {
        $row = IndexRow::make(new EntryRef('journal', 'a', 'default'), IndexScope::Full, 'Meadows', '/journal/meadows', link: 'entry::a', locale: 'en', terms: ['tags::meadows', 'tags::meadows', ''], links: ['statamic://entry::b', 'entry::b', '{entry:12@1:url||https://x.test/a}', '/Contact/', 'mailto:a@b.test', '#gw-link:contact']);

        $this->assertSame(['tags::meadows'], $row->terms);
        $this->assertSame(['entry::b', 'entry:12', 'path:/contact'], $row->links);
        $this->assertEquals($row, IndexRow::fromArray($row->toArray()));
        $this->assertSame(['category:5'], $row->withRelations(terms: ['category:5'])->terms);
        $this->assertSame(['entry::b', 'entry:12', 'path:/contact'], $row->withRelations(terms: [])->links, 'Null keeps them.');
        $this->assertSame(['tags::meadows'], $row->withScope(IndexScope::Link)->withIndexed('2026-10-08')->terms);
    }

    public function test_the_digest_shows_the_type(): void
    {
        $row = IndexRow::make(new EntryRef('pages', 'contact'), IndexScope::Link, 'Contact us', '/contact', 'Get in touch.', 'Pages', link: 'entry::contact');
        $digest = new SiteDigest([$row->digest(), new DigestEntry(null, 'No type', '/x')]);

        $this->assertSame("e1 \"Contact us\" (Pages) /contact: Get in touch.\ne2 \"No type\" /x", $digest->render());
        $this->assertSame('Pages', DigestEntry::fromArray($row->digest()->toArray())->type);
    }
}
