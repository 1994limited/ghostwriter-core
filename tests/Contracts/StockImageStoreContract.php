<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Person;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageQuery;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use ReflectionClass;

/**
 * What every StockImageStore must do. See SessionStoreContract for how an
 * addon runs it. Records are made with fixed moments, whole seconds, so
 * nothing here waits.
 *
 * Override contractUser() where user IDs must exist, and contractAsset()
 * where assets must (Craft's asset IDs).
 */
trait StockImageStoreContract
{
    abstract protected function stockImageStore(): StockImageStore;

    abstract protected function storeFormat(): Format;

    protected function contractUser(int $n): int|string
    {
        return $this->storeFormat() === Format::Statamic ? "user-{$n}" : $n;
    }

    /**
     * The nth test asset, as the addon refers to it.
     */
    protected function contractAsset(int $n): AssetRef
    {
        return match ($this->storeFormat()) {
            Format::Statamic => AssetRef::statamic('assets', "stock/photo-{$n}.jpg"),
            Format::Craft => AssetRef::craft($n, 'uploads', "stock/photo-{$n}.jpg"),
            Format::Filament => AssetRef::filament('public', "stock/photo-{$n}.jpg"),
        };
    }

    public function test_a_saved_record_comes_back_with_every_field(): void
    {
        $store = $this->stockImageStore();
        $at = new DateTimeImmutable('2026-10-02T09:15:00+00:00');
        $by = new Person($this->contractUser(1), 'Ann Editor');
        $image = $this->preview(1, '1234567', $at, 'getty', new Usage('entry', 42, 'hero.image', 'default', 'Hero: Image', live: true));
        $quote = new Quote('1234567', 'premiumaccess:1', 'Premium Access, 2,400 px', Cost::units(1, Cost::DOWNLOAD), 'premiumaccess', '2400', expiresAt: $at->modify('+1 hour'), terms: 'https://example.com/terms');
        $image->quoted($quote, $by, $at->modify('+1 minute'));
        $image->beginLicensing($quote, $by, $at->modify('+2 minutes'));
        $image->licensed(new Licence(
            'getty', '1234567', 'order-9', $at->modify('+3 minutes'), 'Ann Editor', 'premiumaccess:1', Cost::units(1, Cost::DOWNLOAD),
            creditLine: 'Kim Lee/Getty Images', licenceType: Offer::ROYALTY_FREE, restrictions: 'Not for packaging', productType: 'premiumaccess',
            termEndsAt: new DateTimeImmutable('2027-01-01T00:00:00+00:00'), key: $image->id, raw: ['id' => 'order-9', 'uri' => 'https://delivery.example.com/x?token=secret'],
        ), true, $by, $at->modify('+3 minutes'));
        $store->save($image);

        $found = $store->find($image->id);

        $this->assertNotNull($found);
        $this->assertSame(StockImage::LICENSED, $found->state());
        $this->assertSame('2026-10-02 09:18:00', $found->stateChangedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame(['getty', '1234567'], [$found->library, $found->externalId]);
        $this->assertSame($this->contractAsset(1)->key(), $found->asset->key());
        $this->assertSame('stock/photo-1.jpg', $found->asset->path);
        $this->assertSame('Rocks at dusk', $found->title);
        $this->assertSame('Kim Lee/Getty Images', $found->creditLine);
        $this->assertSame('https://example.com/credit', $found->creditUrl);
        $this->assertSame([Offer::ROYALTY_FREE, 'Not for packaging', 'premiumaccess'], [$found->licenceType, $found->restrictions, $found->productType]);
        $this->assertSame('2027-01-01', $found->termEndsAt?->format('Y-m-d'));
        $this->assertTrue($found->noModelInput);
        $this->assertTrue($found->isReplaced());
        $this->assertNull($found->comp(), 'The comp goes once licensed.');
        $this->assertCount(1, $found->compDownloads());
        $this->assertSame('premiumaccess:1', $found->quote()?->option);
        $this->assertSame('1 download', $found->quote()?->cost?->label());
        $this->assertSame('order-9', $found->licence()?->orderId);
        $this->assertSame($image->id, $found->licence()?->key);
        $this->assertSame(['id' => 'order-9'], $found->licence()?->raw, 'Signed addresses are not kept.');
        $this->assertSame((string) $this->contractUser(1), (string) $found->insertedBy?->id);
        $this->assertSame('Ann Editor', $found->insertedBy?->name);
        $this->assertSame($at->getTimestamp(), $found->insertedAt->getTimestamp());

        $this->assertCount(1, $found->usages());
        $usage = $found->usages()[0];
        $this->assertSame(['entry', '42', 'hero.image', 'default', 'Hero: Image', true], [$usage->ownerType, (string) $usage->ownerId, $usage->field, $usage->site, $usage->label, $usage->live]);

        $this->assertSame(
            [HistoryEvent::INSERTED, HistoryEvent::USAGE_ADDED, HistoryEvent::QUOTED, HistoryEvent::LICENSING, HistoryEvent::LICENSED, HistoryEvent::REPLACED],
            array_map(fn (HistoryEvent $event) => $event->event, $found->history()),
        );
        $this->assertSame(array_map(fn (HistoryEvent $event) => $event->id, $image->history()), array_map(fn (HistoryEvent $event) => $event->id, $found->history()));
        $this->assertSame('order-9', $found->history()[4]->detail['order_id'] ?? null);
        $this->assertSame('Ann Editor', $found->history()[4]->by?->name);
    }

    public function test_nothing_is_found_for_a_missing_or_malformed_id(): void
    {
        $store = $this->stockImageStore();

        $this->assertNull($store->find($this->storeFormat()->newId()));

        foreach (['', '../../etc/passwd', str_repeat('a', 300)] as $id) {
            $this->assertNull($store->find($id), "Nothing for \"{$id}\"");
        }
    }

    public function test_an_asset_finds_its_newest_record_that_isnt_removed(): void
    {
        $store = $this->stockImageStore();
        $old = $this->preview(1, 'a', new DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $old->removed(null, new DateTimeImmutable('2026-10-01T11:00:00+00:00'));
        $store->save($old);

        $this->assertNull($store->forAsset($this->contractAsset(1)));

        $live = $store->save($this->preview(1, 'b', new DateTimeImmutable('2026-10-02T10:00:00+00:00')));
        $store->save($this->preview(2, 'c', new DateTimeImmutable('2026-10-03T10:00:00+00:00')));

        $this->assertSame($live->id, $store->forAsset($this->contractAsset(1))?->id);
        $this->assertNull($store->forAsset($this->contractAsset(3)));
    }

    public function test_a_photos_records_are_found_by_its_library_and_id(): void
    {
        $store = $this->stockImageStore();
        $first = $store->save($this->preview(1, '777', new DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $second = $store->save($this->preview(2, '777', new DateTimeImmutable('2026-10-02T10:00:00+00:00')));
        $store->save($this->preview(3, '777', new DateTimeImmutable('2026-10-02T10:00:00+00:00'), 'istock'));

        $this->assertSame([$second->id, $first->id], array_map(fn (StockImage $image) => $image->id, $store->forExternal('getty', '777')));
        $this->assertSame([], $store->forExternal('getty', '778'));
    }

    public function test_a_query_filters_orders_newest_first_and_pages(): void
    {
        $store = $this->stockImageStore();
        $made = [];

        foreach (range(1, 5) as $n) {
            $image = $this->preview($n, (string) $n, new DateTimeImmutable("2026-10-0{$n}T10:00:00+00:00"), $n === 5 ? 'istock' : 'getty', new Usage('entry', $n % 2 === 0 ? 10 : 11, 'hero', 'default'));

            if ($n === 2) {
                $image->beginLicensing(new Quote((string) $n, 'x', 'X'), null, new DateTimeImmutable('2026-10-06T10:00:00+00:00'));
            }

            $made[$n] = $store->save($image)->id;
        }

        $ids = fn (StockImageQuery $query) => array_map(fn (StockImage $image) => $image->id, $store->query($query)->images);

        $this->assertSame([$made[5], $made[4], $made[3], $made[2], $made[1]], $ids(new StockImageQuery));
        $this->assertSame([$made[4], $made[3], $made[1]], $ids(new StockImageQuery([StockImage::PREVIEW], library: 'getty')));
        $this->assertSame([$made[2]], $ids(new StockImageQuery([StockImage::LICENSING])));
        $this->assertSame([$made[4], $made[2]], $ids(new StockImageQuery(ownerType: 'entry', ownerId: 10)));
        $this->assertSame([$made[4], $made[2]], $ids(new StockImageQuery(ownerType: 'entry', ownerId: '10', site: 'default')));
        $this->assertSame([], $ids(new StockImageQuery(ownerType: 'entry', ownerId: 10, site: 'fr')));
        $this->assertSame([$made[5], $made[4]], $ids(new StockImageQuery(since: new DateTimeImmutable('2026-10-04T00:00:00+00:00'))));

        $page = $store->query(new StockImageQuery(page: 2, perPage: 2));
        $this->assertSame([$made[3], $made[2]], array_map(fn (StockImage $image) => $image->id, $page->images));
        $this->assertSame(5, $page->total);
        $this->assertTrue($page->hasMore());
        $this->assertFalse($store->query(new StockImageQuery(page: 3, perPage: 2))->hasMore());
    }

    public function test_previews_older_than_a_cutoff_are_listed_for_cleanup(): void
    {
        $store = $this->stockImageStore();
        $old = $store->save($this->preview(1, 'a', new DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        $store->save($this->preview(2, 'b', new DateTimeImmutable('2026-10-01T10:00:00+00:00')));
        $licensing = $this->preview(3, 'c', new DateTimeImmutable('2026-09-01T10:00:00+00:00'));
        $licensing->beginLicensing(new Quote('c', 'x', 'X'), null, new DateTimeImmutable('2026-09-02T10:00:00+00:00'));
        $store->save($licensing);

        $this->assertSame([$old->id], array_map(fn (StockImage $image) => $image->id, $store->previewsBefore(new DateTimeImmutable('2026-09-15T00:00:00+00:00'))));
    }

    public function test_a_licensed_record_never_goes_back_to_being_a_preview(): void
    {
        $store = $this->stockImageStore();
        $at = new DateTimeImmutable('2026-10-02T10:00:00+00:00');
        $image = $this->preview(1, 'a', $at);
        $image->beginLicensing(new Quote('a', 'x', 'X'), null, $at);
        $image->licensed(new Licence('getty', 'a', 'order-1', $at), true, null, $at);
        $store->save($image);

        $this->assertRefused(fn () => $store->save($this->withState($store, $image->id, StockImage::PREVIEW)));

        $image = $store->find($image->id);
        $this->assertNotNull($image);
        $image->removed(null, $at->modify('+1 day'));
        $store->save($image);

        $this->assertRefused(fn () => $store->save($this->withState($store, $image->id, StockImage::PREVIEW)));
        $this->assertRefused(fn () => $store->save($this->withState($store, $image->id, StockImage::LICENSED)));
        $this->assertSame('order-1', $store->find($image->id)?->licence()?->orderId, 'The licence stays on file.');
    }

    public function test_history_only_grows(): void
    {
        $store = $this->stockImageStore();
        $image = $store->save($this->preview(1, 'a', new DateTimeImmutable('2026-10-02T10:00:00+00:00'), usage: new Usage('entry', 1, 'hero')));
        $data = $image->toArray($this->storeFormat());
        $data['history'] = array_slice((array) $data['history'], 0, 1);

        $this->assertRefused(fn () => $store->save(StockImage::fromArray($data, $this->storeFormat())));
        $this->assertCount(2, $store->find($image->id)?->history() ?? []);
    }

    public function test_two_saves_of_one_record_keep_both_histories_and_the_last_ones_fields(): void
    {
        $store = $this->stockImageStore();
        $at = new DateTimeImmutable('2026-10-02T10:00:00+00:00');
        $image = $store->save($this->preview(1, 'a', $at));

        $first = $store->find($image->id);
        $second = $store->find($image->id);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $first->syncUsagesOn('entry', 7, null, ['body.0.image' => 'Body: Image'], false, null, $at->modify('+1 minute'));
        $store->save($first);
        $second->quoted(new Quote('a', 'creditpack', 'Credit pack', Cost::units(3, Cost::CREDIT)), null, $at->modify('+2 minutes'));
        $second->title = 'Rocks at dawn';
        $store->save($second);

        $found = $store->find($image->id);
        $this->assertNotNull($found);
        $this->assertSame('Rocks at dawn', $found->title, 'The last save\'s fields win.');
        $this->assertSame('creditpack', $found->quote()?->option);
        $this->assertSame(
            [HistoryEvent::INSERTED, HistoryEvent::USAGE_ADDED, HistoryEvent::QUOTED],
            array_map(fn (HistoryEvent $event) => $event->event, $found->history()),
            'Both saves\' events are kept, in time order.',
        );
    }

    public function test_there_is_no_way_to_delete_a_record(): void
    {
        $methods = array_map(fn (\ReflectionMethod $method) => $method->getName(), (new ReflectionClass(StockImageStore::class))->getMethods());

        $this->assertSame([], array_values(array_filter($methods, fn (string $name) => preg_match('/delete|remove|clear|forget|purge/i', $name) === 1)));
    }

    protected function preview(int $asset, string $externalId, DateTimeImmutable $at, string $library = 'getty', ?Usage $usage = null): StockImage
    {
        $photo = new Photo(
            $library, $externalId, "https://images.example.com/{$externalId}.jpg", 'Kim Lee/Getty Images', 'https://example.com/credit', 'Royalty-free',
            title: 'Rocks at dusk', offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD)),
        );

        return StockImage::preview($this->storeFormat(), $photo, $this->contractAsset($asset), "comp-{$externalId}", $at->modify('+30 days'), new Person($this->contractUser(1), 'Ann Editor'), $usage, now: $at);
    }

    private function withState(StockImageStore $store, string $id, string $state): StockImage
    {
        $data = $store->find($id)?->toArray($this->storeFormat()) ?? [];
        $data['state'] = $state;
        $data['history'] = [...(array) ($data['history'] ?? []), HistoryEvent::make('edited', new DateTimeImmutable)->toArray($this->storeFormat())];

        return StockImage::fromArray($data, $this->storeFormat());
    }

    private function assertRefused(callable $save): void
    {
        try {
            $save();
            $this->fail('Expected the save to be refused.');
        } catch (Conflict $refused) {
            $this->assertSame(409, $refused->status());
        }
    }
}
