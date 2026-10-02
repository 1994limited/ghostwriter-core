<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Person;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use PHPUnit\Framework\TestCase;

final class StockImagesTest extends TestCase
{
    private DateTimeImmutable $now;

    private InMemoryStockImageStore $store;

    private InMemoryLock $lock;

    private Person $ann;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $this->ann = new Person('u1', 'Ann');
    }

    public function test_a_preview_is_licensed_through_the_states_under_its_lock(): void
    {
        $stock = $this->stock();
        $image = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', $this->now->modify('+30 days'), $this->ann, new Usage('entry', 'e1', 'hero'));

        $this->assertSame(StockImage::PREVIEW, $image->state());
        $this->assertTrue($image->isUnlicensed());
        $this->assertTrue($image->noModelInput, 'A paid preview may go to no model.');
        $this->assertSame('Rocks at dusk', $image->title);
        $this->assertSame('Kim/Getty Images', $image->creditLine);

        $this->later(60);
        $licensing = $stock->beginLicensing($image->id, $this->quote('123'), $this->ann);
        $this->assertSame(StockImage::LICENSING, $licensing->state());
        $this->assertSame(['stock:'.$image->id], $this->lock->taken);
        $this->assertSame($image->id, $licensing->history()[2]->detail['key'] ?? null, 'The record\'s ID is the idempotency key.');

        $licensed = $stock->licensed($image->id, $this->licence('123', 'order-1'), true, $this->ann);
        $this->assertSame(StockImage::LICENSED, $licensed->state());
        $this->assertTrue($licensed->isReplaced());
        $this->assertFalse($licensed->isUnlicensed());
        $this->assertNull($licensed->comp());
        $this->assertSame('premiumaccess', $licensed->productType);
        $this->assertSame(
            [HistoryEvent::INSERTED, HistoryEvent::USAGE_ADDED, HistoryEvent::LICENSING, HistoryEvent::LICENSED, HistoryEvent::REPLACED],
            $this->events($licensed),
        );
    }

    public function test_a_licence_is_begun_once_and_never_for_a_licensed_image(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));

        $this->assertConflict(fn () => $stock->beginLicensing($id, $this->quote('123')), "don't buy it again");

        $stock->licensed($id, $this->licence('123', 'order-1'), true);

        $this->assertConflict(fn () => $stock->beginLicensing($id, $this->quote('123')), 'already licensed');
        $this->assertConflict(fn () => $stock->failed($id, 'x'), "can't go from licensed to failed");
    }

    public function test_a_failed_licence_may_be_tried_again(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));
        $failed = $stock->failed($id, 'Your account has no downloads left.');

        $this->assertSame(StockImage::FAILED, $failed->state());
        $this->assertSame('Your account has no downloads left.', $failed->error());
        $this->assertTrue($failed->isUnlicensed());

        $again = $stock->beginLicensing($id, $this->quote('123'));
        $this->assertSame(StockImage::LICENSING, $again->state());
        $this->assertNull($again->error());
    }

    public function test_a_free_photo_is_licensed_at_once(): void
    {
        $free = new Photo('pexels', '77', 'https://images.example.com/77.jpg', 'Joe on Pexels', 'https://www.pexels.com/photo/x-77/', 'Pexels licence', 'Brown rocks');
        $image = $this->stock()->recordFree($free, $this->asset(), $this->ann, new Usage('entry', 'e1', 'hero'));

        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertTrue($image->isReplaced());
        $this->assertTrue($image->wasLicensed());
        $this->assertFalse($image->noModelInput);
        $this->assertSame(Offer::FREE_LICENCE, $image->licenceType);
        $this->assertSame('Pexels licence', $image->history()[0]->detail['licence'] ?? null);

        $removed = $this->stock()->removed($image->id);
        $this->assertTrue($removed->wasLicensed(), 'A free photo that was used stays on the ledger as licensed.');
    }

    public function test_licensed_but_not_replaced_can_be_finished_without_buying_again(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));
        $stock->licensed($id, $this->licence('123', 'order-1'), false);
        $stuck = $stock->replaceFailed($id, 'The file could not be saved.');

        $this->assertSame(StockImage::LICENSED, $stuck->state());
        $this->assertFalse($stuck->isReplaced());
        $this->assertSame('The file could not be saved.', $stuck->error());

        $done = $stock->replaced($id);
        $this->assertTrue($done->isReplaced());
        $this->assertNull($done->error());
        $this->assertSame('order-1', $done->licence()?->orderId);
    }

    public function test_reconcile_finds_the_licence_bought_with_the_records_key(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));
        $asked = 0;

        $image = $stock->reconcile($id, function (StockImage $image) use (&$asked, $id) {
            $asked++;

            return [$this->licence('123', 'someone-else', key: 'another-key'), $this->licence('123', 'ours', key: $id)];
        });

        $this->assertSame(1, $asked);
        $this->assertSame(StockImage::LICENSED, $image->state());
        $this->assertSame('ours', $image->licence()?->orderId);
        $this->assertFalse($image->isReplaced(), 'The file is fetched after, as for any licence.');
        $this->assertSame([HistoryEvent::RECONCILED, HistoryEvent::LICENSED], array_slice($this->events($image), -2));
    }

    public function test_reconcile_takes_a_licence_bought_since_that_no_other_record_holds(): void
    {
        $stock = $this->stock();
        $earlier = $stock->recordPreview($this->paid('123'), $this->asset(2), 'comp-0', null)->id;
        $stock->beginLicensing($earlier, $this->quote('123'));
        $stock->licensed($earlier, $this->licence('123', 'order-old'), true);

        $this->later(3600);
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));

        $image = $stock->reconcile($id, fn () => [
            $this->licence('123', 'order-old', $this->now->modify('+5 seconds')),
            $this->licence('123', 'order-before', $this->now->modify('-1 day')),
            $this->licence('123', 'order-new', $this->now->modify('+5 seconds')),
        ]);

        $this->assertSame('order-new', $image->licence()?->orderId);
    }

    public function test_reconcile_waits_before_calling_a_purchase_failed(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('123'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('123'));

        $this->later(60);
        $this->assertSame(StockImage::LICENSING, $stock->reconcile($id, fn () => [])->state(), 'Too soon to say it didn\'t go through.');
        $this->assertSame([], $stock->dueForReconcile());

        $this->later(StockImage::RECONCILE_AFTER);
        $this->assertSame([$id], array_map(fn (StockImage $image) => $image->id, $stock->dueForReconcile()));

        $image = $stock->reconcile($id, fn () => []);
        $this->assertSame(StockImage::FAILED, $image->state());
        $this->assertStringContainsString('has no licence for this image', (string) $image->error());
        $this->assertSame([HistoryEvent::RECONCILED, HistoryEvent::FAILED], array_slice($this->events($image), -2));

        $asked = false;
        $this->assertSame(StockImage::FAILED, $stock->reconcile($id, function () use (&$asked) {
            $asked = true;

            return [];
        })->state());
        $this->assertFalse($asked, 'Only a licence in flight is reconciled.');
    }

    public function test_usages_follow_the_records_that_hold_the_image(): void
    {
        $stock = $this->stock();
        $hero = $stock->recordPreview($this->paid('1'), $this->asset(1), 'comp-1', null, usage: new Usage('entry', 'e1', 'hero', 'en', 'Hero'));
        $card = $stock->recordPreview($this->paid('2'), $this->asset(2), 'comp-2', null);

        $this->later(60);
        $changed = $stock->syncUsages('entry', 'e1', 'en', [
            ['asset' => $this->asset(2), 'field' => 'cards.0.image', 'label' => 'Cards: Image'],
            ['asset' => $this->asset(9), 'field' => 'gallery'],
        ], live: true);

        $this->assertCount(2, $changed);
        $this->assertSame([], $stock->get($hero->id)->usages(), 'No longer on that entry.');
        $this->assertSame(HistoryEvent::USAGE_REMOVED, $this->events($stock->get($hero->id))[2]);
        $usage = $stock->get($card->id)->usages()[0];
        $this->assertSame(['entry', 'e1', 'en', 'cards.0.image', 'Cards: Image', true], [$usage->ownerType, $usage->ownerId, $usage->site, $usage->field, $usage->label, $usage->live]);

        $this->assertSame([$card->id], array_map(fn (StockImage $image) => $image->id, $stock->unlicensedIn('entry', 'e1')));
        $this->assertSame([], $stock->unlicensedIn('entry', 'e1', 'fr'));
        $this->assertSame([$card->id], array_map(fn (StockImage $image) => $image->id, $stock->unlicensedAmong([$this->asset(2), $this->asset(9)])));

        $this->later(60);
        $this->assertSame([], $stock->syncUsages('entry', 'e1', 'en', [['asset' => $this->asset(2), 'field' => 'cards.0.image', 'label' => 'Cards: Image']], live: true), 'Nothing new.');
        $this->assertSame($this->now->modify('-60 seconds')->getTimestamp(), $stock->get($card->id)->usages()[0]->firstSeen?->getTimestamp(), 'First seen is kept.');

        $stock->beginLicensing($card->id, $this->quote('2'));
        $stock->licensed($card->id, $this->licence('2', 'order-2'), true);
        $this->assertSame([], $stock->unlicensedIn('entry', 'e1'));
    }

    public function test_a_comp_may_be_refreshed_once(): void
    {
        $stock = $this->stock();
        $image = $stock->recordPreview($this->paid('1'), $this->asset(), 'comp-1', $this->now->modify('+30 days'));
        $this->assertTrue($image->mayRefreshComp());

        $this->later(86400 * 30);
        $expired = $stock->compExpired($image->id);
        $this->assertNull($expired->comp());
        $this->assertSame(StockImage::PREVIEW, $expired->state(), 'The stand-in stays.');

        $refreshed = $stock->compRefreshed($image->id, 'comp-2', $this->now->modify('+30 days'));
        $this->assertSame('comp-2', $refreshed->comp());
        $this->assertCount(2, $refreshed->compDownloads());
        $this->assertFalse($refreshed->mayRefreshComp());

        $this->assertConflict(fn () => $stock->compRefreshed($image->id, 'comp-3', $this->now->modify('+30 days')), 'refreshed once already');
    }

    public function test_a_removed_image_keeps_its_licence_and_a_licence_in_flight_cant_be_removed(): void
    {
        $stock = $this->stock();
        $id = $stock->recordPreview($this->paid('1'), $this->asset(), 'comp-1', null)->id;
        $stock->beginLicensing($id, $this->quote('1'));

        $this->assertConflict(fn () => $stock->removed($id), "can't go from licensing to removed");

        $stock->licensed($id, $this->licence('1', 'order-1'), true);
        $removed = $stock->removed($id);

        $this->assertSame(StockImage::REMOVED, $removed->state());
        $this->assertSame('order-1', $removed->licence()?->orderId);
        $this->assertTrue($removed->wasLicensed());
        $this->assertEquals($removed->history(), $stock->removed($id)->history(), 'Removing twice changes nothing.');
        $this->assertNull($this->store->forAsset($this->asset()));
    }

    public function test_an_unknown_record_is_not_found(): void
    {
        $this->expectException(NotFound::class);

        $this->stock()->beginLicensing('nothing', $this->quote('1'));
    }

    public function test_the_states_only_move_forward(): void
    {
        $this->assertTrue(StockImage::reaches(StockImage::PREVIEW, StockImage::LICENSED));
        $this->assertTrue(StockImage::reaches(StockImage::FAILED, StockImage::LICENSED));
        $this->assertTrue(StockImage::reaches(StockImage::LICENSED, StockImage::REMOVED));
        $this->assertFalse(StockImage::reaches(StockImage::LICENSED, StockImage::PREVIEW));
        $this->assertFalse(StockImage::reaches(StockImage::LICENSED, StockImage::FAILED));
        $this->assertFalse(StockImage::reaches(StockImage::REMOVED, StockImage::LICENSED));
        $this->assertFalse(StockImage::reaches(StockImage::LICENSING, StockImage::PREVIEW));
    }

    private function stock(): StockImages
    {
        $this->store ??= new InMemoryStockImageStore(DomainOptions::statamic()->format);
        $this->lock ??= new InMemoryLock;

        return new StockImages($this->store, $this->lock, DomainOptions::statamic(), fn () => $this->now);
    }

    private function later(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    private function asset(int $n = 1): AssetRef
    {
        return AssetRef::statamic('assets', "stock/rocks-{$n}.jpg");
    }

    private function paid(string $id): Photo
    {
        return new Photo('getty', $id, "https://images.example.com/{$id}.jpg", 'Kim/Getty Images', null, 'Royalty-free', 'Rocks at dusk', offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD)));
    }

    private function quote(string $id): Quote
    {
        return new Quote($id, 'premiumaccess', 'Premium Access', Cost::units(1, Cost::DOWNLOAD), 'premiumaccess');
    }

    private function licence(string $id, string $order, ?DateTimeImmutable $at = null, ?string $key = null): Licence
    {
        return new Licence('getty', $id, $order, $at ?? $this->now, 'Ann', 'premiumaccess', Cost::units(1, Cost::DOWNLOAD), creditLine: 'Kim/Getty Images', productType: 'premiumaccess', key: $key);
    }

    /**
     * @return array<int, string>
     */
    private function events(StockImage $image): array
    {
        return array_map(fn (HistoryEvent $event) => $event->event, $image->history());
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
