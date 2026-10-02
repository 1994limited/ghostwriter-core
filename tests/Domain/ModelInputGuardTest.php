<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoRanker;
use NineteenNinetyFour\Ghostwriter\Core\Images\ReferenceImage;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ImagerySample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

/**
 * No Getty or iStock image the site holds reaches a model: not as a
 * reference for photo-picker, not as a sample for the imagery analyst.
 */
final class ModelInputGuardTest extends ImagesTestCase
{
    private InMemoryStockImageStore $ledger;

    private AssetRef $gettyAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new InMemoryStockImageStore(Format::Statamic);
        $this->gettyAsset = AssetRef::statamic('assets', 'stock/rocks.jpg');
        $getty = new Photo('getty', '123', 'https://images.example.com/123.jpg', 'Kim/Getty Images', null, 'Royalty-free', offer: Offer::paid());
        $this->ledger->save(StockImage::preview(Format::Statamic, $getty, $this->gettyAsset, 'comp-1', null, now: new DateTimeImmutable('2026-10-02T10:00:00+00:00')));
        $this->route('https://images.example.com/', $this->jpegResponse(60, 40));
    }

    public function test_a_ledger_getty_asset_a_getty_file_name_and_embedded_getty_credits_are_refused(): void
    {
        $guard = new ModelInputGuard($this->ledger, $this->logger());

        $this->assertFalse($guard->allows($this->gettyAsset));
        $this->assertTrue($guard->allows(AssetRef::statamic('assets', 'stock/other.jpg')));
        $this->assertFalse($guard->allows(AssetRef::filament('public', 'uploads/GettyImages-1234567.jpg')));
        $this->assertFalse($guard->allowsFilename('iStock-998877.jpg'));
        $this->assertTrue($guard->allowsFilename('my-istock-photo.jpg'), 'Only the names Getty\'s downloads have.');

        $this->assertTrue($guard->allows(self::jpeg()));
        $this->assertFalse($guard->allows(self::withIptc(['110' => 'Getty Images'])), 'IPTC credit.');
        $this->assertFalse($guard->allows(self::withIptc(['116' => '2024 iStockphoto LP'])), 'IPTC copyright notice.');
        $this->assertTrue($guard->allows(self::withIptc(['110' => 'Ann Lee', '116' => 'Ann Lee 2024'])));
        $this->assertFalse($guard->allows(new Image(self::withXmp('<photoshop:Credit>Getty Images</photoshop:Credit>'), 'image/jpeg')), 'XMP credit.');
        $this->assertFalse($guard->allows(self::withXmp('<rdf:Description photoshop:Credit="iStock / Getty Images Plus"/>')), 'XMP credit as an attribute.');
        $this->assertTrue($guard->allows(self::withXmp('<dc:description>Not from Getty Images</dc:description>')), 'Only credit, source and rights fields count.');

        $this->assertNotSame([], array_filter($this->logs, fn (array $log) => $log['level'] === 'debug' && str_contains($log['message'], 'left out of a model call')));
    }

    public function test_a_record_that_says_no_model_input_is_refused_whatever_its_library(): void
    {
        $asset = AssetRef::statamic('assets', 'stock/adobe.jpg');
        $adobe = new Photo('adobe', '9', 'https://images.example.com/9.jpg', 'Adobe Stock', null, 'Standard', offer: Offer::paid());
        $this->ledger->save(StockImage::preview(Format::Statamic, $adobe, $asset, null, null));
        $free = AssetRef::statamic('assets', 'stock/pexels.jpg');
        $this->ledger->save(StockImage::free(Format::Statamic, new Photo('pexels', '1', 'https://images.example.com/1.jpg', 'Joe on Pexels', null, 'Pexels licence'), $free));

        $guard = new ModelInputGuard($this->ledger);

        $this->assertFalse($guard->allows($asset));
        $this->assertTrue($guard->allows($free));
    }

    public function test_photo_picker_never_sees_a_getty_reference(): void
    {
        $fake = new FakeProvider;
        $fake->respond('photo-picker', '1: fits');
        $ranker = new PhotoRanker($this->stock(), $fake, new PromptLibrary(Vocabulary::statamic()), guard: new ModelInputGuard($this->ledger));

        $ranking = $ranker->rank([self::photo('a')], PhotoContext::make('Rocks'), [
            new ReferenceImage(self::jpeg(30, 20), $this->gettyAsset),
            new ReferenceImage(self::jpeg(31, 20), filename: 'GettyImages-555.jpg'),
            self::withIptc(['110' => 'Getty Images']),
            new ReferenceImage(self::jpeg(32, 20), AssetRef::statamic('assets', 'stock/own.jpg'), 'own.jpg'),
        ]);

        $this->assertTrue($ranking->judged);
        $this->assertTrue($ranking->withReferences);
        $fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 2 && str_contains($request->prompt, 'The first 1 image(s) are the references'));
    }

    public function test_the_guard_checks_bytes_even_without_a_ledger(): void
    {
        $fake = new FakeProvider;
        $fake->respond('photo-picker', '1: fits');

        (new PhotoRanker($this->stock(), $fake, new PromptLibrary(Vocabulary::statamic())))
            ->rank([self::photo('a')], PhotoContext::make('Rocks'), [self::withIptc(['110' => 'Getty Images'])]);

        $fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 1 && str_contains($request->prompt, 'There are no reference images'));
    }

    public function test_the_imagery_analyst_never_sees_a_getty_sample(): void
    {
        $fake = new FakeProvider;
        $fake->respond('imagery-analyst', '<document>Warm.</document>');
        $own = new Image(self::jpeg(), 'image/jpeg');
        $studio = new Studio($fake, new PromptLibrary(Vocabulary::craft()), guard: new ModelInputGuard($this->ledger));

        $studio->analyseImagery('News', [
            new ImagerySample('Hero', 'Getty one', new Image(self::jpeg(), 'image/jpeg'), $this->gettyAsset),
            new ImagerySample('Hero', 'Named one', new Image(self::jpeg(), 'image/jpeg'), filename: 'iStock-123.jpg'),
            new ImagerySample('Hero', 'Credited one', new Image(self::withIptc(['116' => 'Getty Images']), 'image/jpeg')),
            new ImagerySample('Hero', 'Our own', $own),
        ]);

        $fake->assertSent('imagery-analyst', fn (TextRequest $request) => $request->images === [$own]
            && str_contains($request->prompt, '1. Hero, on "Our own"')
            && ! str_contains($request->prompt, 'Getty one'));
    }

    /**
     * A JPEG with these IPTC datasets (record 2): 110 credit, 116 copyright.
     *
     * @param  array<string, string>  $tags
     */
    private static function withIptc(array $tags): string
    {
        $iptc = '';

        foreach ($tags as $tag => $value) {
            $iptc .= chr(0x1C).chr(2).chr((int) $tag).pack('n', strlen($value)).$value;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'gw-iptc');
        file_put_contents($path, self::jpeg());

        try {
            return (string) iptcembed($iptc, $path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A JPEG with an XMP packet holding this.
     */
    private static function withXmp(string $inside): string
    {
        $xmp = "http://ns.adobe.com/xap/1.0/\0".'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description>'.$inside.'</rdf:Description></rdf:RDF></x:xmpmeta>';
        $jpeg = self::jpeg();

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp.substr($jpeg, 2);
    }
}
