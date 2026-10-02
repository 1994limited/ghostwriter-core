<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Person;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\MemoryAssetReplacer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * "License & replace" over FakeLibrary: a photo is licensed exactly once,
 * whatever goes wrong.
 */
final class StockLicensingTest extends TestCase
{
    private DateTimeImmutable $now;

    private FakeLibrary $library;

    private MemoryAssetReplacer $replacer;

    private StockImages $stock;

    private Person $ann;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $this->library = (new FakeLibrary('getty', 'Getty Images', clock: fn () => $this->now))->withPhotos('123');
        $this->replacer = new MemoryAssetReplacer;
        $this->stock = new StockImages(new InMemoryStockImageStore(DomainOptions::craft()->format), new InMemoryLock, DomainOptions::craft(), fn () => $this->now);
        $this->ann = new Person(7, 'Ann');
    }

    public function test_license_and_replace_buys_once_and_swaps_the_file_as_delivered(): void
    {
        $id = $this->preview();
        $quote = $this->library->quotes('123')[0];

        $image = $this->stock->license($id, $this->library, $quote, $this->replacer, $this->ann);

        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertTrue($image->isReplaced());
        $this->assertSame('demo-order-1', $image->licence()?->orderId);
        $this->assertSame($id, $image->licence()?->key, 'The record\'s ID went to the library as the key.');
        $this->assertSame(1, $this->library->licenceCalls('123'));
        $this->assertSame(['123', $quote, $id, 'Ann'], $this->licenceCall()[1]);
        $this->assertSame(
            [HistoryEvent::INSERTED, HistoryEvent::LICENSING, HistoryEvent::LICENSED, HistoryEvent::REPLACED],
            array_map(fn (HistoryEvent $event) => $event->event, $image->history()),
        );

        $this->assertCount(1, $this->replacer->replaced);
        $replaced = $this->replacer->replaced[0];
        $this->assertSame(AssetRef::craft(42, 'uploads', 'stock/rocks.jpg')->key(), $replaced['asset']->key());
        $this->assertSame($this->downloadedBytes(), $replaced['file']->content, 'The licensed file is passed on byte for byte.');
        $this->assertSame([$id, 'Demo photographer/Getty Images'], [$replaced['meta']->ledgerId, $replaced['meta']->creditLine]);
        $this->assertObjectNotHasProperty('title', $replaced['meta']);
        $this->assertObjectNotHasProperty('alt', $replaced['meta']);

        $this->assertConflict(fn () => $this->stock->license($id, $this->library, $quote, $this->replacer), 'already licensed');
        $this->assertSame(1, $this->library->licenceCalls('123'), 'Pressing again buys nothing.');
    }

    public function test_an_uncertain_purchase_is_never_retried_and_is_settled_from_the_librarys_licences(): void
    {
        $id = $this->preview();
        $quote = $this->library->quotes('123')[0];
        $this->library->licenceOutcomes(FakeLibrary::UNCERTAIN_CHARGED);

        $this->assertUncertain(fn () => $this->stock->license($id, $this->library, $quote, $this->replacer));
        $this->assertSame(StockImage::LICENSING, $this->stock->get($id)->state());

        foreach (range(1, 3) as $retry) {
            $this->assertConflict(fn () => $this->stock->license($id, $this->library, $quote, $this->replacer), "don't buy it again");
        }

        $this->assertSame(1, $this->library->licenceCalls('123'));

        $image = $this->stock->reconcile($id, fn (StockImage $image) => $this->library->findLicences($image->externalId));
        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertSame('demo-order-1', $image->licence()?->orderId);
        $this->assertFalse($image->isReplaced());

        $done = $this->stock->replaceAgain($id, $this->library, $this->replacer, $this->ann);
        $this->assertTrue($done->isReplaced());
        $this->assertSame(1, $this->library->licenceCalls('123'), 'Licensed exactly once.');
        $this->assertCount(1, $this->library->bought('123'));
    }

    public function test_an_uncertain_purchase_that_didnt_charge_fails_after_the_wait_and_may_be_bought_then(): void
    {
        $id = $this->preview();
        $quote = $this->library->quotes('123')[0];
        $this->library->licenceOutcomes(FakeLibrary::UNCERTAIN_NOT_CHARGED);

        $this->assertUncertain(fn () => $this->stock->license($id, $this->library, $quote, $this->replacer));

        $find = fn (StockImage $image) => $this->library->findLicences($image->externalId);
        $this->assertSame(StockImage::LICENSING, $this->stock->reconcile($id, $find)->state(), 'Too soon to say.');

        $this->now = $this->now->modify('+11 minutes');
        $this->assertSame(StockImage::FAILED, $this->stock->reconcile($id, $find)->state());

        $image = $this->stock->license($id, $this->library, $quote, $this->replacer);
        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertSame(2, $this->library->licenceCalls('123'));
        $this->assertCount(1, $this->library->bought('123'), 'Bought once in all.');
    }

    public function test_a_plain_refusal_fails_the_record_so_it_can_be_tried_again(): void
    {
        $id = $this->preview();
        $newQuote = new Quote('123', 'demo-pack', 'Demo licence', Cost::units(2, Cost::DOWNLOAD));
        $this->library->licenceOutcomes(new QuoteChanged(quote: $newQuote), new InsufficientBalance('Your account has no downloads left.'));
        $quote = $this->library->quotes('123')[0];

        try {
            $this->stock->license($id, $this->library, $quote, $this->replacer);
            $this->fail('Expected the changed price to be reported.');
        } catch (QuoteChanged $changed) {
            $this->assertSame('2 downloads', $changed->quote?->costLabel());
        }

        $this->assertSame(StockImage::FAILED, $this->stock->get($id)->state());

        try {
            $this->stock->license($id, $this->library, $newQuote, $this->replacer);
            $this->fail('Expected the empty balance to be reported.');
        } catch (InsufficientBalance $empty) {
            $this->assertSame('Your account has no downloads left.', $empty->getMessage());
            $this->assertInstanceOf(PhotoUnavailable::class, $empty, 'Existing catches still work.');
        }

        $failed = $this->stock->get($id);
        $this->assertSame('Your account has no downloads left.', $failed->error());
        $this->assertSame([], $this->replacer->replaced);
        $this->assertSame(StockImage::LICENSED, $this->stock->license($id, $this->library, $newQuote, $this->replacer)->state());
    }

    public function test_a_file_that_cant_be_put_in_place_keeps_the_licence_for_download_again_and_replace(): void
    {
        $id = $this->preview();
        $this->replacer->failing = 1;

        $image = $this->stock->license($id, $this->library, $this->library->quotes('123')[0], $this->replacer);

        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertFalse($image->isReplaced());
        $this->assertStringContainsString('The disk is full.', (string) $image->error());

        $this->replacer->movesTo = AssetRef::craft(43, 'uploads', 'stock/rocks-2.jpg');
        $done = $this->stock->replaceAgain($id, $this->library, $this->replacer);

        $this->assertTrue($done->isReplaced());
        $this->assertNull($done->error());
        $this->assertSame('id:43', $done->asset->key(), 'Where the addon put it.');
        $this->assertSame(1, $this->library->licenceCalls('123'));
        $this->assertEquals($done->history(), $this->stock->replaceAgain($id, $this->library, $this->replacer)->history(), 'Nothing more to do.');
    }

    public function test_anything_unexpected_from_the_library_counts_as_uncertain(): void
    {
        $id = $this->preview();
        $this->library->licenceOutcomes(new RuntimeException('Undefined index: id'));

        $this->assertUncertain(fn () => $this->stock->license($id, $this->library, $this->library->quotes('123')[0], $this->replacer));
        $this->assertSame(StockImage::LICENSING, $this->stock->get($id)->state());
    }

    private function preview(): string
    {
        return $this->stock->recordPreview($this->library->photoFor('123'), AssetRef::craft(42, 'uploads', 'stock/rocks.jpg'), 'comp-1', $this->now->modify('+30 days'), $this->ann)->id;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function licenceCall(): array
    {
        return array_values(array_filter($this->library->calls, fn (array $call) => $call[0] === 'license'))[0];
    }

    private function downloadedBytes(): string
    {
        $download = array_values(array_filter($this->library->calls, fn (array $call) => $call[0] === 'download'))[0][1][0];

        return $this->library->download($download)->content;
    }

    private function assertUncertain(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected the purchase to be uncertain.');
        } catch (LicensingUncertain $uncertain) {
            $this->assertStringContainsString("don't buy it again", $uncertain->getMessage());
        }
    }

    private function assertConflict(callable $call, string $words): void
    {
        try {
            $call();
            $this->fail("Expected a conflict: {$words}");
        } catch (Conflict $conflict) {
            $this->assertStringContainsString($words, $conflict->getMessage());
        }
    }
}
