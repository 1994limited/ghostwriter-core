<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

final class FakeLibraryTest extends ImagesTestCase
{
    public function test_it_is_a_paid_library_that_no_model_may_see(): void
    {
        $library = new FakeLibrary;

        $this->assertInstanceOf(LicensableLibrary::class, $library);
        $this->assertSame(['demo', 'Demo stock (no charge)'], [$library->id(), $library->label()]);
        $this->assertFalse($library->capabilities()->free);
        $this->assertFalse($library->capabilities()->mayRank);
        $this->assertTrue($library->capabilities()->noModelInput);
        $this->assertSame(30, $library->capabilities()->previewKeepDays);

        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [$library->withPhotos('a', 'b')]);
        $this->assertSame(['demo'], $stock->sources());
        $this->assertFalse($stock->mayRank($library->photoFor('a')));
    }

    public function test_search_finds_its_photos_with_paid_offers_a_page_at_a_time(): void
    {
        $library = (new FakeLibrary)->withPhotos('a', 'b', 'c');

        $photos = $library->search(new SearchQuery('rocks', perPage: 2));
        $this->assertSame(['a', 'b'], array_map(fn (Photo $photo) => $photo->id, $photos));
        $this->assertSame('rocks', $photos[0]->term);
        $this->assertSame('1 download', $photos[0]->offer()->label());
        $this->assertSame(['c'], array_map(fn (Photo $photo) => $photo->id, $library->search(new SearchQuery('rocks', page: 2, perPage: 2))));
        $this->assertSame('b', $library->photo('b')->id);

        foreach (['zzz', '../a', ''] as $id) {
            try {
                $library->photo($id);
                $this->fail("Expected \"{$id}\" to be refused.");
            } catch (PhotoUnavailable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LicenceRefused::class);
        $library->fetch('a');
    }

    public function test_a_comp_is_a_watermarked_picture_at_the_photos_aspect_kept_for_the_terms_days(): void
    {
        $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $library = (new FakeLibrary(clock: fn () => $now))->withPhotos((new FakeLibrary)->photoFor('a', width: 1000, height: 2000));

        $preview = $library->preview('a');
        $size = getimagesizefromstring((string) $preview->file?->content);

        $this->assertTrue($preview->watermarked);
        $this->assertTrue($preview->isStored());
        $this->assertNotFalse($size);
        $this->assertSame(['image/png', 400, 800], [$size['mime'], $size[0], $size[1]]);
        $this->assertSame('2026-11-01', $preview->keepUntil->format('Y-m-d'));
        $this->assertNotSame($preview->file?->content, $library->download($library->license('a', $library->quotes('a')[0], 'k', 'Ann'))->content, 'The licensed file has no watermark.');
    }

    public function test_licence_outcomes_are_scripted_and_every_call_recorded(): void
    {
        $library = (new FakeLibrary)->withPhotos('a');
        $quote = $library->quotes('a')[0];
        $library->licenceOutcomes(new InsufficientBalance('None left.'), FakeLibrary::UNCERTAIN_NOT_CHARGED, FakeLibrary::UNCERTAIN_CHARGED);

        foreach ([InsufficientBalance::class, LicensingUncertain::class, LicensingUncertain::class] as $expected) {
            try {
                $library->license('a', $quote, 'record-1', 'Ann');
                $this->fail("Expected {$expected}.");
            } catch (PhotoUnavailable $exception) {
                $this->assertInstanceOf($expected, $exception);
            }
        }

        $this->assertCount(1, $library->findLicences('a'), 'Only the charged one was bought.');
        $this->assertSame('record-1', $library->findLicences('a')[0]->key);

        $licence = $library->license('a', $quote, 'record-2', 'Ann');
        $this->assertSame('demo-order-2', $licence->orderId);
        $this->assertSame(4, $library->licenceCalls('a'));
        $this->assertCount(2, $library->bought('a'));
        $this->assertSame('Demo account', $library->account()->name);
        $this->assertSame('100 downloads', $library->account()->product('demo-pack')['remaining']?->label());
    }
}
