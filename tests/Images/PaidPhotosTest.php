<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoRanker;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries\StubLibrary;

/**
 * StockSearch as a set of libraries, and how photos from a paid library
 * are kept away from a model.
 */
final class PaidPhotosTest extends ImagesTestCase
{
    private FakeProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeProvider;
        $this->route('https://images.example.com/', $this->jpegResponse(60, 40));
        $this->route('https://images.paid.example/', $this->jpegResponse(60, 40));
    }

    public function test_more_libraries_come_after_the_free_ones_and_can_replace_one(): void
    {
        $paid = new StubLibrary('getty', $this->paidCapabilities(), label: 'Getty Images');
        $demo = new StubLibrary('pexels', Capabilities::free('2026-10-02'), label: 'Demo stock');
        $stock = new StockSearch($this->http, $this->credentials, true, libraries: [$paid, $demo]);

        $this->assertSame(['unsplash', 'pexels', 'pixabay', 'openverse', 'getty'], array_keys($stock->libraries()));
        $this->assertSame(['pexels', 'openverse', 'getty'], $stock->sources());
        $this->assertSame('Getty Images', $stock->label('getty'));
        $this->assertSame('Demo stock', $stock->label('pexels'));
        $this->assertSame('Unsplash', $stock->label('unsplash'));
        $this->assertSame('flickr', $stock->label('flickr'));
        $this->assertSame($paid, $stock->library('getty'));
        $this->assertNull($stock->library('flickr'));

        $paid->available = false;
        $this->assertSame(['pexels', 'openverse'], $stock->sources());
    }

    public function test_a_search_can_be_kept_to_some_libraries(): void
    {
        $paid = new StubLibrary('getty', $this->paidCapabilities(), [$this->paidPhoto('1')]);
        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [$paid]);

        $this->assertSame([], $stock->search('pottery', sources: ['unsplash']));
        $this->assertSame([], $paid->searches);

        $photos = $stock->search('mended pottery', Shape::Portrait, 4, sources: ['getty', 'flickr']);

        $this->assertSame(['1'], array_map(fn (Photo $photo) => $photo->id, $photos));
        $this->assertSame('mended pottery', $photos[0]->term);
        $this->assertSame(Shape::Portrait, $paid->searches[0]->shape);
        $this->assertSame(4, $paid->searches[0]->perPage);
    }

    public function test_a_paid_library_does_not_hand_over_its_file(): void
    {
        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [new StubLibrary('getty', $this->paidCapabilities(), [$this->paidPhoto('1')])]);

        $this->expectException(PhotoUnavailable::class);
        $stock->fetch('getty', '1');
    }

    public function test_only_libraries_that_allow_it_are_ranked(): void
    {
        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [
            new StubLibrary('getty', $this->paidCapabilities()),
            new StubLibrary('cleared', Capabilities::paid(Capabilities::QUOTES_BALANCE, 90, mayRank: true)),
        ]);

        $this->assertTrue($stock->mayRank(self::photo('a')));
        $this->assertFalse($stock->mayRank($this->paidPhoto('1')));
        $this->assertTrue($stock->mayRank($this->paidPhoto('1', 'cleared')), 'A paid library whose terms allow it.');
        $this->assertFalse($stock->mayRank($this->paidPhoto('1', 'nowhere')), 'An unknown library\'s paid photo is never shown.');
        $this->assertTrue($stock->mayRank(self::photo('a', source: 'nowhere')));
    }

    public function test_the_free_libraries_are_judged_as_before(): void
    {
        $stock = $this->stock();

        foreach (['unsplash', 'pexels', 'pixabay', 'openverse'] as $source) {
            $this->assertTrue($stock->mayRank(self::photo('a', source: $source)), $source);
            $this->assertFalse($stock->library($source)?->capabilities()->noModelInput);
        }
    }

    public function test_the_model_never_sees_a_paid_photo_which_follows_the_judged_ones_in_its_own_order(): void
    {
        $this->fake->respond('photo-picker', "2: a hand-thrown bowl\n1: close enough");
        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [new StubLibrary('getty', $this->paidCapabilities())]);
        $candidates = [
            $this->paidPhoto('g2'),
            self::photo('a', 'pottery', description: 'a carved table'),
            $this->paidPhoto('g1'),
            self::photo('b', 'pottery', description: 'a hand-thrown bowl'),
        ];

        $ranking = (new PhotoRanker($stock, $this->fake, new PromptLibrary(Vocabulary::craft())))->rank($candidates, $this->context());

        $this->assertTrue($ranking->judged);
        $this->assertSame(['b', 'a', 'g2', 'g1'], array_map(fn (Photo $photo) => $photo->id, $ranking->photos));
        $this->assertSame([true, true, false, false], array_map(fn (Photo $photo) => $photo->picked, $ranking->photos));
        $this->assertNull($ranking->photos[2]->reason);

        $this->fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 2
            && ! str_contains($request->prompt, 'Getty caption')
            && ! str_contains($request->prompt, 'getty tag')
            && str_contains($request->prompt, 'The 2 images are the candidates'));
        $this->assertNotContains('https://images.paid.example/g1.jpg', $this->requested(), 'Not even its thumbnail is fetched for judging.');
    }

    public function test_paid_photos_alone_come_back_unjudged_without_asking_a_model(): void
    {
        $stock = new StockSearch($this->http, $this->credentials, false, libraries: [new StubLibrary('getty', $this->paidCapabilities(), [$this->paidPhoto('g1'), $this->paidPhoto('g2')])]);
        $finder = new PhotoFinder($stock, $this->fake, new PromptLibrary(Vocabulary::craft()));

        $results = $finder->find($this->context(), [], 'pottery');

        $this->assertFalse($results->judged);
        $this->assertSame(['g1', 'g2'], array_map(fn (Photo $photo) => $photo->id, $results->photos));
        $this->assertSame([], $results->picked());
        $this->fake->assertNotSent('photo-picker');
    }

    public function test_a_paid_photo_keeps_its_offer_through_an_array_and_a_free_ones_array_is_unchanged(): void
    {
        $paid = $this->paidPhoto('g1');
        $array = $paid->toArray();

        $this->assertSame('1 download', $array['offer']['label'] ?? null);
        $this->assertTrue($array['editorial'] ?? null);
        $this->assertSame('Editorial use only', $array['restrictions'] ?? null);
        $this->assertSame('Signature', $array['collection'] ?? null);
        $this->assertEquals($paid, Photo::fromArray($array));
        $this->assertEquals($paid, Photo::fromArray(json_decode((string) json_encode($array), true)));
        $this->assertFalse($paid->isFree());
        $this->assertFalse($paid->judged(true, 'x')->withTerm('y')->isFree(), 'Copies keep the offer.');

        $free = self::photo('a');
        $this->assertSame(['source', 'id', 'thumb', 'credit', 'credit_url', 'licence', 'title', 'description', 'tags', 'width', 'height', 'url', 'term', 'picked', 'reason', 'alt', 'asset_title'], array_keys($free->toArray()));
        $this->assertTrue($free->isFree());
        $this->assertTrue($free->offer()->free);
    }

    private function paidCapabilities(): Capabilities
    {
        return Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, termsCheckedAt: '2026-10-02');
    }

    private function paidPhoto(string $id, string $source = 'getty'): Photo
    {
        return new Photo(
            $source, $id, "https://images.paid.example/{$id}.jpg", 'Kim via Getty Images', null, 'Royalty-free',
            title: 'Getty caption', tags: ['getty tag'], term: 'pottery',
            offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD)), editorial: true, restrictions: 'Editorial use only', collection: 'Signature',
        );
    }

    private function context(): PhotoContext
    {
        return PhotoContext::make('Repairing old pots', 'Hero: Image');
    }
}
