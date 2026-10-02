<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Preview;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;
use PHPUnit\Framework\TestCase;

final class ValuesTest extends TestCase
{
    public function test_a_free_library_may_be_ranked_and_a_paid_one_may_not_by_default(): void
    {
        $free = Capabilities::free('2026-10-02');
        $paid = Capabilities::paid(Capabilities::QUOTES_CREDITS, 30, termsCheckedAt: '2026-10-02', editorial: true, creditRequired: true);

        $this->assertTrue($free->free);
        $this->assertTrue($free->mayRank);
        $this->assertNull($free->previewKeepDays);
        $this->assertFalse($paid->free);
        $this->assertFalse($paid->mayRank);
        $this->assertTrue($paid->noModelInput, 'No asset from a paid library goes to any model.');
        $this->assertFalse($free->noModelInput);
        $this->assertFalse(Capabilities::free(mayRank: false)->mayRank);
        $this->assertSame(30, $paid->previewKeepDays);
        $this->assertSame(Capabilities::STORAGE_PRIVATE, $paid->previewStorage);
        $this->assertSame('credits', $paid->toArray()['quotes']);
        $this->assertFalse($paid->toArray()['may_rank']);
    }

    public function test_capabilities_refuse_unknown_words(): void
    {
        foreach ([
            fn () => new Capabilities(false, false, quotes: 'cheap'),
            fn () => new Capabilities(false, false, previewStorage: 'public'),
            fn () => Capabilities::free('October'),
        ] as $make) {
            try {
                $make();
                $this->fail('Expected it to be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_cost_is_money_in_minor_units_or_units_of_an_allowance(): void
    {
        $this->assertSame('GBP 9.00', Cost::money(900, 'gbp')->label());
        $this->assertSame('1 download', Cost::units(1, Cost::DOWNLOAD)->label());
        $this->assertSame('about 3 credits', Cost::units(3, Cost::CREDIT, exact: false)->label());
        $this->assertSame(['amount' => 1250, 'currency' => 'EUR', 'exact' => true], Cost::money(1250, 'EUR')->toArray());

        foreach ([Cost::money(1250, 'EUR'), Cost::units(2, Cost::LICENCE)->estimated()] as $cost) {
            $this->assertEquals($cost, Cost::fromArray($cost->toArray()));
            $this->assertEquals($cost, Cost::fromArray(json_decode((string) json_encode($cost->toArray()), true)));
        }

        $this->assertNull(Cost::fromArray(['amount' => 5]));
        $this->assertNull(Cost::fromArray(['units' => 1, 'unit' => 'banana']));

        $this->expectException(InvalidArgumentException::class);
        Cost::money(-1, 'GBP');
    }

    public function test_an_offer_says_free_paid_or_what_it_costs(): void
    {
        $this->assertSame('Free', Offer::free()->label());
        $this->assertSame('Paid', Offer::paid()->label());
        $this->assertSame('3 credits', Offer::paid(Cost::units(3, Cost::CREDIT))->label());

        $offer = Offer::paid(Cost::units(1, Cost::DOWNLOAD), Offer::EDITORIAL, ['premiumaccess']);
        $this->assertEquals($offer, Offer::fromArray($offer->toArray()));
        $this->assertNull(Offer::fromArray(['price' => null]));

        $this->expectException(InvalidArgumentException::class);
        new Offer(false, licenceType: 'forever');
    }

    public function test_a_search_query_is_tidied(): void
    {
        $query = new SearchQuery('  pottery  ', 'portrait', page: 0, perPage: 500);

        $this->assertSame('pottery', $query->term);
        $this->assertSame(Shape::Portrait, $query->shape);
        $this->assertSame(1, $query->page);
        $this->assertSame(100, $query->perPage);
        $this->assertFalse($query->editorial, 'Editorial images are off unless asked for.');
        $this->assertSame('clay', $query->withTerm('clay')->term);
        $this->assertSame(100, $query->withTerm('clay')->perPage);
    }

    public function test_a_preview_is_bytes_kept_for_a_while_or_only_the_providers_address(): void
    {
        $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $file = new PhotoFile(ImagesTestCase::jpegBytes(), 'image/jpeg', 'jpg', ImagesTestCase::photoFor('a'));

        $stored = Preview::stored($file, 30, now: $now);
        $this->assertTrue($stored->isStored());
        $this->assertSame('2026-11-01', $stored->keepUntil->format('Y-m-d'));
        $this->assertFalse($stored->isExpired($now->modify('+29 days')));
        $this->assertTrue($stored->isExpired($now->modify('+30 days')));

        $linked = Preview::linked('https://image.shutterstock.com/preview.jpg', 1, now: $now);
        $this->assertFalse($linked->isStored());
        $this->assertNull($linked->file);

        $this->expectException(InvalidArgumentException::class);
        new Preview($file, 'https://example.com/a.jpg', true, $now, $now);
    }

    public function test_a_quote_goes_to_an_array_and_back_and_can_expire(): void
    {
        $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $quote = new Quote('123', 'creditpack:extended', 'iStock extended licence', Cost::units(3, Cost::CREDIT), 'creditpack', '2400', true, $now->modify('+10 minutes'), 'https://example.com/terms');

        $this->assertEquals($quote, Quote::fromArray($quote->toArray()));
        $this->assertEquals($quote, Quote::fromArray(json_decode((string) json_encode($quote->toArray()), true)));
        $this->assertSame('3 credits', $quote->costLabel());
        $this->assertSame('not known before licensing', (new Quote('1', 'pack', 'Pack'))->costLabel());
        $this->assertFalse($quote->isExpired($now));
        $this->assertTrue($quote->isExpired($now->modify('+10 minutes')));
        $this->assertNull(Quote::fromArray(['option' => 'x']));
    }

    public function test_a_licence_keeps_no_signed_address_or_token(): void
    {
        $licence = new Licence(
            'getty', '123', 'order-9', new DateTimeImmutable('2026-10-02T12:00:00+00:00'), 'Ann', 'premiumaccess', Cost::units(1, Cost::DOWNLOAD),
            creditLine: 'Kim/Getty Images', productType: 'premiumaccess', termEndsAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00'), key: 'record-1',
            raw: ['id' => 'order-9', 'uri' => 'https://delivery.example.com/a.jpg?sig=abc', 'page' => 'https://example.com/photo/123', 'nested' => ['access_token' => 'secret', 'size' => 2400]],
        );

        $array = $licence->toArray();

        $this->assertSame(['id' => 'order-9', 'page' => 'https://example.com/photo/123', 'nested' => ['size' => 2400]], $array['raw']);
        $this->assertStringNotContainsString('secret', (string) json_encode($array));
        $this->assertStringNotContainsString('sig=abc', (string) json_encode($array));
        $back = Licence::fromArray($array);
        $this->assertNotNull($back);
        $this->assertSame(['order-9', 'record-1', '2027-01-01', '1 download'], [$back->orderId, $back->key, $back->termEndsAt?->format('Y-m-d'), $back->cost?->label()]);
        $this->assertNull(Licence::fromArray(['library' => 'getty']));
    }
}
