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
        $this->assertSame(['conta'], $row->stemsOf('title'));
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
        $this->assertSame(['garde', 'desig'], $old->stemsOf('title'));
        $this->assertNull(IndexRow::fromArray(['title' => 'No entry']));
    }

    public function test_the_digest_shows_the_type(): void
    {
        $row = IndexRow::make(new EntryRef('pages', 'contact'), IndexScope::Link, 'Contact us', '/contact', 'Get in touch.', 'Pages', link: 'entry::contact');
        $digest = new SiteDigest([$row->digest(), new DigestEntry(null, 'No type', '/x')]);

        $this->assertSame("e1 \"Contact us\" (Pages) /contact: Get in touch.\ne2 \"No type\" /x", $digest->render());
        $this->assertSame('Pages', DigestEntry::fromArray($row->digest()->toArray())->type);
    }
}
