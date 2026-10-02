<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;

/**
 * The stock image ledger and its rules, over a StockImageStore:
 *
 * - every change to a record is made under its lock (`stock:<id>`), on the
 *   record as it stands then;
 * - a licence is begun once: a second attempt while one is in flight, or
 *   its outcome unknown, is refused, so nothing is bought twice;
 * - a licence whose outcome is unknown is settled by reconcile(), asking
 *   the library which licences it has for the photo, and never by buying
 *   again;
 * - where each image is used is kept up to date from the records that
 *   hold it (syncUsages()), and the publish guard asks unlicensedIn().
 */
final class StockImages
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly StockImageStore $store,
        private readonly Lock $lock,
        private readonly DomainOptions $options,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function store(): StockImageStore
    {
        return $this->store;
    }

    /**
     * A paid photo put in as a preview (the stand-in asset), its comp kept
     * privately at `$comp` until `$compKeepUntil`.
     */
    public function recordPreview(Photo $photo, AssetRef $asset, ?string $comp, ?DateTimeImmutable $compKeepUntil, ?Person $by = null, ?Usage $usage = null, bool $noModelInput = true): StockImage
    {
        return $this->store->save(StockImage::preview($this->options->format, $photo, $asset, $comp, $compKeepUntil, $by, $usage, $noModelInput, $this->now()));
    }

    /**
     * A free library's photo, saved as the final file: licensed at once.
     */
    public function recordFree(Photo $photo, AssetRef $asset, ?Person $by = null, ?Usage $usage = null, bool $noModelInput = false): StockImage
    {
        return $this->store->save(StockImage::free($this->options->format, $photo, $asset, $by, $usage, $noModelInput, $this->now()));
    }

    /**
     * @throws NotFound
     */
    public function get(string $id): StockImage
    {
        return $this->store->find($id) ?? throw new NotFound('No such stock image.');
    }

    public function find(string $id): ?StockImage
    {
        return $this->store->find($id);
    }

    /**
     * A change to the record as it stands now, under its lock. NotFound
     * when there is no such record; a Conflict from the change goes
     * through, and nothing is saved.
     *
     * @param  callable(StockImage, DateTimeImmutable): void  $change
     */
    public function change(string $id, callable $change): StockImage
    {
        return $this->lock->run('stock:'.$id, function () use ($id, $change) {
            $image = $this->get($id);
            $change($image, $this->now());

            return $this->store->save($image);
        });
    }

    /**
     * The option the person was shown at confirm.
     */
    public function quoted(string $id, Quote $quote, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->quoted($quote, $by, $now));
    }

    /**
     * Saved as `licensing` before the provider is called, so a second
     * attempt is refused (Conflict), as is one for an image already
     * licensed. The record's ID is the purchase's idempotency key.
     */
    public function beginLicensing(string $id, Quote $quote, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->beginLicensing($quote, $by, $now));
    }

    public function licensed(string $id, Licence $licence, bool $replaced, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->licensed($licence, $replaced, $by, $now));
    }

    /**
     * The licensed file is in place of the stand-in ("Download again and
     * replace" finished).
     */
    public function replaced(string $id, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->replaced($by, $now));
    }

    public function replaceFailed(string $id, string $error): StockImage
    {
        return $this->change($id, fn (StockImage $image) => $image->replaceFailed($error));
    }

    /**
     * The licence call definitely didn't charge.
     */
    public function failed(string $id, string $error, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->failed($error, $by, $now));
    }

    /**
     * Settles a licence whose outcome wasn't known, from the licences the
     * library has for the photo (`$findLicences`, the library's
     * findLicences()):
     *
     * - one with this record's key, or bought from when licensing began
     *   and not already on another record: licensed (not yet replaced);
     * - none, once RECONCILE_AFTER has passed: failed, so it may be tried
     *   again;
     * - none yet: left as it is, to ask again later.
     *
     * Anything but a `licensing` record comes back unchanged.
     *
     * @param  callable(StockImage): array<int, Licence>  $findLicences
     */
    public function reconcile(string $id, callable $findLicences, ?Person $by = null): StockImage
    {
        $image = $this->get($id);

        if (! $image->is(StockImage::LICENSING)) {
            return $image;
        }

        // Asked outside the lock: it is a network call.
        $licences = $findLicences($image);
        $taken = $this->ordersOnOtherRecords($image);

        return $this->change($id, function (StockImage $image, DateTimeImmutable $now) use ($licences, $taken, $by) {
            if (! $image->is(StockImage::LICENSING)) {
                return;
            }

            $found = self::match($image, $licences, $taken);

            if ($found !== null) {
                $image->licensed($found, false, $by, $now, reconciled: true);
            } elseif ($image->isDueForReconcile($now)) {
                $image->failed("The purchase didn't go through: {$image->library} has no licence for this image. It can be tried again.", $by, $now, reconciled: true);
            }
        });
    }

    /**
     * `licensing` records old enough to reconcile.
     *
     * @return array<int, StockImage>
     */
    public function dueForReconcile(): array
    {
        $now = $this->now();

        return array_values(array_filter($this->all(new StockImageQuery([StockImage::LICENSING])), fn (StockImage $image) => $image->isDueForReconcile($now)));
    }

    /**
     * "Refresh preview": the comp downloaded again, once at most; a second
     * refresh is refused (Conflict).
     */
    public function compRefreshed(string $id, string $comp, DateTimeImmutable $keepUntil, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->compRefreshed($comp, $keepUntil, $by, $now));
    }

    /**
     * The comp's bytes have gone at the end of its period.
     */
    public function compExpired(string $id): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->compExpired($now));
    }

    /**
     * The asset has gone, or the preview was cleaned up. A licence stays.
     */
    public function removed(string $id, ?Person $by = null): StockImage
    {
        return $this->change($id, fn (StockImage $image, DateTimeImmutable $now) => $image->removed($by, $now));
    }

    /**
     * Where ledger assets are used on one record now, from the addon's scan
     * of it: each asset found, the field it is in and a label. Usages on
     * this record that are no longer there go.
     *
     * @param  array<int, array{asset: AssetRef, field: string, label?: string|null}>  $found
     * @return array<int, StockImage> The records that changed.
     */
    public function syncUsages(string $ownerType, int|string $ownerId, ?string $site, array $found, bool $live = false, ?Person $by = null): array
    {
        /** @var array<string, array<string, string|null>> $fields By record ID: field => label. */
        $fields = [];

        foreach ($found as $usage) {
            $image = $this->store->forAsset($usage['asset']);

            if ($image !== null) {
                $fields[$image->id][$usage['field']] = $usage['label'] ?? null;
            }
        }

        foreach ($this->all(new StockImageQuery(ownerType: $ownerType, ownerId: $ownerId, site: $site)) as $image) {
            $fields[$image->id] ??= [];
        }

        $changed = [];

        foreach ($fields as $id => $here) {
            $this->lock->run('stock:'.$id, function () use ($id, $here, $ownerType, $ownerId, $site, $live, $by, &$changed) {
                $image = $this->store->find((string) $id);

                if ($image !== null && $image->syncUsagesOn($ownerType, $ownerId, $site, $here, $live, $by, $this->now())) {
                    $changed[] = $this->store->save($image);
                }
            });
        }

        return $changed;
    }

    /**
     * The images on one record that aren't licensed yet: what the publish
     * guard refuses.
     *
     * @return array<int, StockImage>
     */
    public function unlicensedIn(string $ownerType, int|string $ownerId, ?string $site = null): array
    {
        return $this->all(new StockImageQuery([StockImage::PREVIEW, StockImage::LICENSING, StockImage::FAILED], ownerType: $ownerType, ownerId: $ownerId, site: $site));
    }

    /**
     * Of these assets (found in a record's data before it is saved), the
     * ones that aren't licensed yet.
     *
     * @param  array<int, AssetRef>  $assets
     * @return array<int, StockImage>
     */
    public function unlicensedAmong(array $assets): array
    {
        $unlicensed = [];

        foreach ($assets as $asset) {
            $image = $this->store->forAsset($asset);

            if ($image !== null && $image->isUnlicensed()) {
                $unlicensed[$image->id] = $image;
            }
        }

        return array_values($unlicensed);
    }

    /**
     * Every record a query finds, all pages.
     *
     * @return array<int, StockImage>
     */
    public function all(StockImageQuery $query): array
    {
        $images = [];
        $page = $query->page(1);

        do {
            $found = $this->store->query($page);
            array_push($images, ...$found->images);
            $page = $page->page($page->page + 1);
        } while ($found->hasMore() && $found->images !== []);

        return $images;
    }

    /**
     * The licence that is this record's: the one bought with its key, else
     * the first bought since licensing began (a minute's grace for clocks)
     * that no other record holds.
     *
     * @param  array<int, Licence>  $licences
     * @param  array<string, true>  $taken  Order IDs on other records.
     */
    private static function match(StockImage $image, array $licences, array $taken): ?Licence
    {
        foreach ($licences as $licence) {
            if ($licence->key === $image->id) {
                return $licence;
            }
        }

        $since = $image->stateChangedAt()->getTimestamp() - 60;

        foreach ($licences as $licence) {
            if ($licence->key === null && ! isset($taken[$licence->orderId]) && $licence->licensedAt->getTimestamp() >= $since) {
                return $licence;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function ordersOnOtherRecords(StockImage $image): array
    {
        $taken = [];

        foreach ($this->store->forExternal($image->library, $image->externalId) as $other) {
            if ($other->id !== $image->id && $other->licence() !== null) {
                $taken[$other->licence()->orderId] = true;
            }
        }

        return $taken;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }
}
