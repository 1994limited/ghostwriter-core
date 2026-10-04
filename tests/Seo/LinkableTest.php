<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Linkable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkableTest extends TestCase
{
    public function test_a_published_page_with_an_address_is_linkable(): void
    {
        $this->assertNull(Linkable::excluded($this->row('/garden-services/winter-care')));
        $this->assertTrue(Linkable::keep($this->row('/x')));
    }

    public function test_a_draft_is_left_out_and_not_kept(): void
    {
        $row = $this->row('/x', published: false);

        $this->assertSame(Linkable::UNPUBLISHED, Linkable::excluded($row));
        $this->assertFalse(Linkable::keep($row));
    }

    public function test_a_scheduled_page_is_linkable_from_its_day(): void
    {
        $row = $this->row('/x', liveFrom: '2026-10-10T09:00:00+00:00');

        $this->assertSame(Linkable::SCHEDULED, Linkable::excluded($row, new DateTimeImmutable('2026-10-09')));
        $this->assertNull(Linkable::excluded($row, new DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->assertTrue(Linkable::keep($row));
    }

    public function test_an_expired_page_is_left_out_from_its_end(): void
    {
        $row = $this->row('/x', liveUntil: '2026-10-10');

        $this->assertNull(Linkable::excluded($row, new DateTimeImmutable('2026-10-09')));
        $this->assertSame(Linkable::EXPIRED, Linkable::excluded($row, new DateTimeImmutable('2026-10-10')));
    }

    public function test_no_address_noindex_and_the_home_page_are_left_out(): void
    {
        $this->assertSame(Linkable::NO_URL, Linkable::excluded($this->row(null)));
        $this->assertFalse(Linkable::keep($this->row(null)));
        $this->assertSame(Linkable::NOINDEX, Linkable::excluded($this->row('/landing', noindex: true)));
        $this->assertSame(Linkable::HOME, Linkable::excluded($this->row('/')));
        $this->assertSame(Linkable::HOME, Linkable::excluded($this->row('https://northfold.test/')));
    }

    #[DataProvider('utility')]
    public function test_utility_pages_are_left_out_in_every_language(string $url): void
    {
        $this->assertSame(Linkable::UTILITY, Linkable::excluded($this->row($url)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function utility(): array
    {
        return [
            'search' => ['/search'], '404' => ['/404'], 'login' => ['/login'], 'cart' => ['/shop/cart'],
            'checkout' => ['/checkout'], 'thank-you' => ['/contact/thank-you'], 'suche' => ['/de/suche'],
            'danke' => ['/danke'], 'recherche' => ['/fr/recherche'], 'merci' => ['/merci'], 'zoeken' => ['/zoeken'],
            'bedankt' => ['/bedankt'], 'buscar' => ['/buscar'], 'gracias' => ['/gracias'], 'html' => ['/search.html'],
        ];
    }

    public function test_a_page_merely_mentioning_a_utility_word_is_linkable(): void
    {
        $this->assertNull(Linkable::excluded($this->row('/search-engine-tips')));
        $this->assertNull(Linkable::excluded($this->row('/thank-you/our-volunteers')));
    }

    private function row(?string $url, bool $published = true, ?string $liveFrom = null, ?string $liveUntil = null, bool $noindex = false): IndexRow
    {
        return IndexRow::make(new EntryRef('pages', 'x'), IndexScope::Link, 'A page', $url, liveFrom: $liveFrom, liveUntil: $liveUntil, noindex: $noindex, published: $published);
    }
}
