<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuardOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PublishGuardContractTest;

/**
 * The publish guard contract against a pretend CMS whose save hook asks
 * PublishReadiness, as each addon's will: it shows the contract can be met
 * with core's guard alone.
 */
final class MemoryPublishGuardTest extends PublishGuardContractTest
{
    private OnPublish $mode = OnPublish::Block;

    private ?StockImages $stock = null;

    protected function guardMode(OnPublish $mode): void
    {
        $this->mode = $mode;
    }

    protected function guardEntry(string $text, bool $stockPreview = false): mixed
    {
        if ($stockPreview) {
            $this->stock()->recordPreview(
                new Photo('getty', '123', 'https://images.example.com/123.jpg', 'Kim/Getty Images', null, 'Royalty-free', 'Rocks', offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD))),
                new AssetRef('assets', 'stock/rocks.jpg'),
                'comp-1',
                new DateTimeImmutable('+30 days'),
            );
        }

        return ['body' => $text, 'image' => $stockPreview ? ['assets::stock/rocks.jpg'] : ['assets::photos/own.jpg']];
    }

    protected function guardPublish(mixed $entry): GuardOutcome
    {
        return $this->save((array) $entry, true);
    }

    protected function guardSaveDraft(mixed $entry): GuardOutcome
    {
        return $this->save((array) $entry, false);
    }

    protected function guardOtherWaysLive(mixed $entry): array
    {
        // A scheduled entry is published with a date to come: the same hook.
        return ['scheduled' => $this->save((array) $entry + ['date' => '2099-01-01'], true)];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(array $values, bool $live): GuardOutcome
    {
        if (! $live) {
            return new GuardOutcome(true);
        }

        $readiness = PublishReadiness::standard($this->mode)->check(new GapContext(
            schema: new Schema([new Field('body', Kind::RichText, 'Body'), new Field('image', Kind::Reference, 'Image', files: true, meta: ['images' => true])]),
            entry: new EntryData($values),
            richText: new MarkdownAsStored,
            placeholders: new MemoryAssets,
            assets: new MemoryAssets,
            stock: $this->stock(),
        ));

        if ($readiness->blocked()) {
            return new GuardOutcome(false, $readiness->byField());
        }

        return new GuardOutcome(true, [], $readiness->warns() ? [$readiness->message()->english()] : []);
    }

    private function stock(): StockImages
    {
        return $this->stock ??= new StockImages(new InMemoryStockImageStore(Format::Statamic), new InMemoryLock, DomainOptions::statamic());
    }
}
