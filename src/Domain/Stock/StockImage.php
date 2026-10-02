<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;

/**
 * One stock image Ghostwriter put into the site, free or paid: the ledger
 * record. Where it is used, its licence state, the provider and its ID,
 * the order, what it cost, who licensed it and when, its credit line and
 * restrictions, and every step on the way (`history`).
 *
 *                     ┌──────── free library: licensed at once ────────┐
 *                     │                                                ▼
 *     insert ──▶ preview ──▶ licensing ──ok──▶ licensed ──(asset gone)──▶ removed
 *                  │           │  ▲                               (record kept)
 *                  │           │  └── reconcile (uncertain) ─┐
 *                  │           └─fail─▶ failed ──retry───────┘
 *                  └──(cleanup, or the editor removes it)──▶ removed
 *
 * - `preview`: a stand-in asset is in the slot and its comp is held
 *   privately for the control panel. Not licensed. Blocks publishing.
 * - `licensing`: a licence call is in flight or its outcome is unknown.
 *   Saved before the provider is called; the record's ID is the purchase's
 *   idempotency key. Never bought again from here: only reconcile() (or a
 *   definite answer) moves it on.
 * - `licensed`: bought (or free), the full file in place (`replaced`).
 *   Permanent.
 * - `failed`: the licence call definitely didn't charge. May be tried
 *   again.
 * - `removed`: the asset is gone. A licensed image's licence stays on file.
 *
 * A record is never deleted, its history only grows, and a licensed one
 * never goes back to being a preview: a store refuses such a save
 * (over()). Kept in the same shape for every addon, keys in snake_case,
 * moments as the format writes them.
 */
final class StockImage
{
    use RoundTrips;

    public const PREVIEW = 'preview';

    public const LICENSING = 'licensing';

    public const LICENSED = 'licensed';

    public const FAILED = 'failed';

    public const REMOVED = 'removed';

    public const STATES = [self::PREVIEW, self::LICENSING, self::LICENSED, self::FAILED, self::REMOVED];

    /** Where each state may go next. */
    public const TRANSITIONS = [
        self::PREVIEW => [self::LICENSING, self::REMOVED],
        self::LICENSING => [self::LICENSED, self::FAILED],
        self::LICENSED => [self::REMOVED],
        self::FAILED => [self::LICENSING, self::REMOVED],
        self::REMOVED => [],
    ];

    /** How many times a comp may be downloaded: the first, and one "Refresh preview" (terms check Q2). */
    public const COMP_DOWNLOADS = 2;

    /** Seconds a `licensing` record waits before reconcile() may call it failed. */
    public const RECONCILE_AFTER = 600;

    /**
     * @param  array<int, DateTimeImmutable>  $compDownloads
     * @param  array<int, Usage>  $usages
     * @param  array<int, HistoryEvent>  $history
     */
    private function __construct(
        public readonly Format $format,
        public readonly string $id,
        public readonly string $library,
        public readonly string $externalId,
        public AssetRef $asset,
        private string $state,
        private DateTimeImmutable $stateChangedAt,
        public readonly DateTimeImmutable $insertedAt,
        public readonly ?Person $insertedBy = null,
        public ?string $title = null,
        public ?string $creditLine = null,
        public ?string $creditUrl = null,
        public ?string $licenceType = null,
        public bool $editorial = false,
        public ?string $restrictions = null,
        public ?string $productType = null,
        public ?DateTimeImmutable $termEndsAt = null,
        public readonly bool $noModelInput = false,
        private ?string $comp = null,
        private ?DateTimeImmutable $compKeepUntil = null,
        private array $compDownloads = [],
        private ?Quote $quote = null,
        private ?Licence $licence = null,
        private bool $replaced = false,
        private ?string $error = null,
        private array $usages = [],
        private array $history = [],
    ) {}

    /**
     * A paid photo put in as a preview: a stand-in asset in the slot, its
     * comp kept privately (`$comp`: where, such as a StoredFile ID; never a
     * Getty or iStock comp address) until `$compKeepUntil`.
     */
    public static function preview(
        Format $format,
        Photo $photo,
        AssetRef $asset,
        ?string $comp,
        ?DateTimeImmutable $compKeepUntil,
        ?Person $by = null,
        ?Usage $usage = null,
        bool $noModelInput = true,
        ?DateTimeImmutable $now = null,
    ): self {
        $now ??= new DateTimeImmutable;
        $image = self::fromPhoto($format, $photo, $asset, self::PREVIEW, $by, $noModelInput, $now);
        $image->comp = $comp;
        $image->compKeepUntil = $compKeepUntil;
        $image->compDownloads = $comp !== null ? [$now] : [];
        $image->record(HistoryEvent::INSERTED, $now, $by, ['state' => self::PREVIEW]);
        $image->addUsage($usage, $now, $by);

        return $image;
    }

    /**
     * A free library's photo, saved as the final file: licensed at once.
     */
    public static function free(
        Format $format,
        Photo $photo,
        AssetRef $asset,
        ?Person $by = null,
        ?Usage $usage = null,
        bool $noModelInput = false,
        ?DateTimeImmutable $now = null,
    ): self {
        $now ??= new DateTimeImmutable;
        $image = self::fromPhoto($format, $photo, $asset, self::LICENSED, $by, $noModelInput, $now);
        $image->replaced = true;
        $image->record(HistoryEvent::INSERTED, $now, $by, ['state' => self::LICENSED, 'licence' => $photo->licence]);
        $image->addUsage($usage, $now, $by);

        return $image;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function stateChangedAt(): DateTimeImmutable
    {
        return $this->stateChangedAt;
    }

    public function comp(): ?string
    {
        return $this->comp;
    }

    public function compKeepUntil(): ?DateTimeImmutable
    {
        return $this->compKeepUntil;
    }

    /**
     * @return array<int, DateTimeImmutable> When each comp was downloaded.
     */
    public function compDownloads(): array
    {
        return $this->compDownloads;
    }

    public function quote(): ?Quote
    {
        return $this->quote;
    }

    public function licence(): ?Licence
    {
        return $this->licence;
    }

    /** Whether the licensed file is in place of the stand-in. */
    public function isReplaced(): bool
    {
        return $this->replaced;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<int, Usage>
     */
    public function usages(): array
    {
        return $this->usages;
    }

    /**
     * @return array<int, HistoryEvent> Oldest first.
     */
    public function history(): array
    {
        return $this->history;
    }

    public function is(string $state): bool
    {
        return $this->state === $state;
    }

    /** Not licensed yet: a preview, a licence in flight, or one that failed. These block publishing. */
    public function isUnlicensed(): bool
    {
        return in_array($this->state, [self::PREVIEW, self::LICENSING, self::FAILED], true);
    }

    /** A licence was bought for it, whatever has happened to the asset since. */
    public function wasLicensed(): bool
    {
        if ($this->licence !== null || $this->is(self::LICENSED)) {
            return true;
        }

        foreach ($this->history as $event) {
            if ($event->event === HistoryEvent::LICENSED || ($event->event === HistoryEvent::INSERTED && ($event->detail['state'] ?? null) === self::LICENSED)) {
                return true;
            }
        }

        return false;
    }

    /** A `licensing` record old enough that reconcile() may call it failed if no licence is found. */
    public function isDueForReconcile(?DateTimeImmutable $now = null): bool
    {
        return $this->is(self::LICENSING) && $this->stateChangedAt->getTimestamp() <= ($now ?? new DateTimeImmutable)->getTimestamp() - self::RECONCILE_AFTER;
    }

    /** Whether "Refresh preview" may download the comp again. */
    public function mayRefreshComp(): bool
    {
        return $this->is(self::PREVIEW) && count($this->compDownloads) < self::COMP_DOWNLOADS;
    }

    /**
     * Whether it is used on this record (on this site; any when null).
     */
    public function isUsedOn(string $ownerType, int|string $ownerId, ?string $site = null): bool
    {
        foreach ($this->usages as $usage) {
            if ($usage->isOn($ownerType, $ownerId, $site)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The option the person was shown at confirm.
     */
    public function quoted(Quote $quote, ?Person $by, DateTimeImmutable $now): void
    {
        $this->quote = $quote;
        $this->record(HistoryEvent::QUOTED, $now, $by, ['option' => $quote->option, 'cost' => $quote->costLabel()]);
    }

    /**
     * About to call the provider. From a preview, or a failed attempt.
     *
     * @throws Conflict when a licence is already being bought, or is bought.
     */
    public function beginLicensing(Quote $quote, ?Person $by, DateTimeImmutable $now): void
    {
        if ($this->is(self::LICENSING)) {
            throw new Conflict('This image is already being licensed. Ghostwriter will check how that went; don\'t buy it again.');
        }

        if (! $this->may(self::LICENSING)) {
            throw new Conflict($this->wasLicensed() ? 'This image is already licensed.' : 'This image can\'t be licensed now.');
        }

        $this->quote = $quote;
        $this->error = null;
        $this->moveTo(self::LICENSING, $now);
        $this->record(HistoryEvent::LICENSING, $now, $by, ['option' => $quote->option, 'cost' => $quote->costLabel(), 'key' => $this->id]);
    }

    /**
     * The licence is bought. `$replaced` is whether the licensed file is
     * already in place of the stand-in; if not, "Download again and
     * replace" finishes it (replaced()), and the licence is never bought
     * twice.
     *
     * @throws Conflict unless a licence was being bought.
     */
    public function licensed(Licence $licence, bool $replaced, ?Person $by, DateTimeImmutable $now, bool $reconciled = false): void
    {
        $this->require(self::LICENSED);

        $this->licence = $licence;
        $this->replaced = $replaced;
        $this->error = null;
        $this->comp = null;
        $this->compKeepUntil = null;
        $this->creditLine = $licence->creditLine ?? $this->creditLine;
        $this->licenceType = $licence->licenceType;
        $this->restrictions = $licence->restrictions ?? $this->restrictions;
        $this->editorial = $this->editorial || $licence->licenceType === Offer::EDITORIAL;
        $this->productType = $licence->productType ?? $this->productType;
        $this->termEndsAt = $licence->termEndsAt ?? $this->termEndsAt;
        $this->moveTo(self::LICENSED, $now);

        if ($reconciled) {
            $this->record(HistoryEvent::RECONCILED, $now, $by, ['found' => true, 'order_id' => $licence->orderId]);
        }

        $this->record(HistoryEvent::LICENSED, $now, $by, ['order_id' => $licence->orderId, 'cost' => $licence->cost?->label(), 'estimated' => $licence->estimated]);

        if ($replaced) {
            $this->record(HistoryEvent::REPLACED, $now, $by);
        }
    }

    /**
     * The licensed file is now in place of the stand-in (at $asset, if the
     * addon had to move it).
     *
     * @throws Conflict unless it is licensed.
     */
    public function replaced(?Person $by, DateTimeImmutable $now, ?AssetRef $asset = null): void
    {
        if (! $this->is(self::LICENSED)) {
            throw new Conflict('Only a licensed image can be replaced with its licensed file.');
        }

        $this->asset = $asset ?? $this->asset;
        $this->replaced = true;
        $this->error = null;
        $this->record(HistoryEvent::REPLACED, $now, $by);
    }

    /**
     * Licensed, but the file couldn't be put in place: the error is kept
     * for "Download again and replace".
     */
    public function replaceFailed(string $error): void
    {
        $this->error = $error;
    }

    /**
     * The licence call definitely didn't charge: the provider said no, or
     * reconciling found nothing.
     *
     * @throws Conflict unless a licence was being bought.
     */
    public function failed(string $error, ?Person $by, DateTimeImmutable $now, bool $reconciled = false): void
    {
        $this->require(self::FAILED);

        $this->error = $error;
        $this->moveTo(self::FAILED, $now);

        if ($reconciled) {
            $this->record(HistoryEvent::RECONCILED, $now, $by, ['found' => false]);
        }

        $this->record(HistoryEvent::FAILED, $now, $by, ['error' => $error]);
    }

    /**
     * The asset has gone (or the preview was cleaned up). A licence stays
     * on file.
     *
     * @throws Conflict while a licence is being bought.
     */
    public function removed(?Person $by, DateTimeImmutable $now): void
    {
        if ($this->is(self::REMOVED)) {
            return;
        }

        $this->require(self::REMOVED);
        $this->comp = null;
        $this->compKeepUntil = null;
        $this->moveTo(self::REMOVED, $now);
        $this->record(HistoryEvent::REMOVED, $now, $by);
    }

    /**
     * The comp was downloaded again ("Refresh preview"), once at most.
     *
     * @throws Conflict when it isn't a preview, or has been refreshed already.
     */
    public function compRefreshed(string $comp, DateTimeImmutable $keepUntil, ?Person $by, DateTimeImmutable $now): void
    {
        if (! $this->mayRefreshComp()) {
            throw new Conflict($this->is(self::PREVIEW) ? 'This preview has been refreshed once already. License it or remove it.' : 'Only a preview has a comp to refresh.');
        }

        $this->comp = $comp;
        $this->compKeepUntil = $keepUntil;
        $this->compDownloads[] = $now;
        $this->record(HistoryEvent::COMP_REFRESHED, $now, $by, ['keep_until' => $this->format->stamp($keepUntil)]);
    }

    /**
     * The comp's period is over and its bytes have gone; the stand-in stays.
     */
    public function compExpired(DateTimeImmutable $now): void
    {
        if ($this->comp === null) {
            return;
        }

        $this->comp = null;
        $this->record(HistoryEvent::COMP_EXPIRED, $now, null);
    }

    /**
     * Where it is used on one record now: each field it was found in
     * (field path => label). Usages on that record no longer found go;
     * those found again are marked seen.
     *
     * @param  array<string, string|null>  $fields
     * @return bool Whether anything changed.
     */
    public function syncUsagesOn(string $ownerType, int|string $ownerId, ?string $site, array $fields, bool $live, ?Person $by, DateTimeImmutable $now): bool
    {
        $changed = false;
        $kept = [];

        foreach ($this->usages as $usage) {
            if (! $usage->isOn($ownerType, $ownerId) || $usage->site !== $site) {
                $kept[] = $usage;
            } elseif (array_key_exists($usage->field, $fields)) {
                $kept[] = $usage->seen($now, $fields[$usage->field], $live);
                $changed = $changed || $usage->live !== $live || ($fields[$usage->field] !== null && $fields[$usage->field] !== $usage->label);
                unset($fields[$usage->field]);
            } else {
                $changed = true;
                $this->record(HistoryEvent::USAGE_REMOVED, $now, $by, ['owner' => $ownerType.':'.$ownerId, 'site' => $site, 'field' => $usage->field]);
            }
        }

        $this->usages = $kept;

        foreach ($fields as $field => $label) {
            $this->addUsage(new Usage($ownerType, $ownerId, (string) $field, $site, $label, $now, $now, $live), $now, $by);
            $changed = true;
        }

        return $changed;
    }

    /**
     * This record, to be saved over the one stored: the history either
     * holds every stored event or adds new ones (any it lacks are merged
     * back in), and the state may only move forward. A store calls this in
     * save().
     *
     * @throws Conflict for a save that would lose history without adding any, or move the state back.
     */
    public function over(?self $stored): self
    {
        if ($stored === null) {
            return $this;
        }

        $mine = [];

        foreach ($this->history as $event) {
            $mine[$event->id] = true;
        }

        $theirs = [];
        $missing = [];

        foreach ($stored->history as $event) {
            $theirs[$event->id] = true;

            if (! isset($mine[$event->id])) {
                $missing[] = $event;
            }
        }

        $added = array_filter($this->history, fn (HistoryEvent $event) => ! isset($theirs[$event->id]));

        if ($missing !== [] && $added === []) {
            throw new Conflict('That stock image record has changed since it was read; its history can\'t be shortened.');
        }

        if ($stored->state !== $this->state && ! self::reaches($stored->state, $this->state)) {
            throw new Conflict("A stock image can't go from {$stored->state} back to {$this->state}.");
        }

        if ($missing !== []) {
            $history = [...$missing, ...$this->history];
            usort($history, fn (HistoryEvent $a, HistoryEvent $b) => $a->at <=> $b->at);
            $this->history = $history;
        }

        return $this;
    }

    /**
     * Whether $to can be reached from $from through TRANSITIONS.
     */
    public static function reaches(string $from, string $to): bool
    {
        $seen = [$from => true];
        $queue = [$from];

        while (($state = array_shift($queue)) !== null) {
            foreach (self::next($state) as $next) {
                if ($next === $to) {
                    return true;
                }

                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Format $format): self
    {
        $string = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? (string) $data[$key] : null;
        $moment = fn (string $key): ?DateTimeImmutable => Format::parse(Usage::moment($data[$key] ?? null));
        $list = fn (string $key): array => is_array($data[$key] ?? null) ? array_values(array_filter($data[$key], 'is_array')) : [];
        $state = $string('state');
        $insertedAt = $moment('inserted_at') ?? new DateTimeImmutable('@0');

        $image = new self(
            $format,
            $string('id') ?? '',
            $string('library') ?? '',
            $string('external_id') ?? '',
            AssetRef::fromArray(is_array($data['asset'] ?? null) ? $data['asset'] : []),
            $state !== null && in_array($state, self::STATES, true) ? $state : self::PREVIEW,
            $moment('state_changed_at') ?? $insertedAt,
            $insertedAt,
            Person::fromArray($data['inserted_by'] ?? null),
            $string('title'),
            $string('credit_line'),
            $string('credit_url'),
            $string('licence_type'),
            (bool) ($data['editorial'] ?? false),
            $string('restrictions'),
            $string('product_type'),
            $moment('term_ends_at'),
            (bool) ($data['no_model_input'] ?? false),
            $string('comp'),
            $moment('comp_keep_until'),
            array_values(array_filter(array_map(fn ($at) => Format::parse(Usage::moment($at)), is_array($data['comp_downloads'] ?? null) ? $data['comp_downloads'] : []))),
            is_array($data['quote'] ?? null) ? Quote::fromArray($data['quote']) : null,
            is_array($data['licence'] ?? null) ? Licence::fromArray($data['licence']) : null,
            (bool) ($data['replaced'] ?? false),
            $string('error'),
            array_values(array_filter(array_map(fn (array $usage) => Usage::fromArray($usage), $list('usages')))),
            array_values(array_filter(array_map(fn (array $event) => HistoryEvent::fromArray($event), $list('history')))),
        );

        return $image->remember($data, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        $stamp = fn (?DateTimeImmutable $at): ?string => $at !== null ? $format->stamp($at) : null;

        return [
            'id' => $this->id,
            'state' => $this->state,
            'state_changed_at' => $stamp($this->stateChangedAt),
            'library' => $this->library,
            'external_id' => $this->externalId,
            'asset' => $this->asset->toArray(),
            'comp' => $this->comp,
            'comp_keep_until' => $stamp($this->compKeepUntil),
            'comp_downloads' => array_map(fn (DateTimeImmutable $at) => $format->stamp($at), $this->compDownloads),
            'title' => $this->title,
            'credit_line' => $this->creditLine,
            'credit_url' => $this->creditUrl,
            'licence_type' => $this->licenceType,
            'editorial' => $this->editorial,
            'restrictions' => $this->restrictions,
            'product_type' => $this->productType,
            'term_ends_at' => $stamp($this->termEndsAt),
            'no_model_input' => $this->noModelInput,
            'quote' => $this->quote?->toArray(),
            'licence' => $this->licence?->toArray(),
            'replaced' => $this->replaced,
            'inserted_by' => $this->insertedBy?->toArray(),
            'inserted_at' => $stamp($this->insertedAt),
            'usages' => array_map(fn (Usage $usage) => $usage->toArray($format), $this->usages),
            'error' => $this->error,
            'history' => array_map(fn (HistoryEvent $event) => $event->toArray($format), $this->history),
        ];
    }

    private static function fromPhoto(Format $format, Photo $photo, AssetRef $asset, string $state, ?Person $by, bool $noModelInput, DateTimeImmutable $now): self
    {
        $offer = $photo->offer();

        return new self(
            $format,
            $format->newId(),
            $photo->source,
            $photo->id,
            $asset,
            $state,
            $now,
            $now,
            $by,
            $photo->title ?? $photo->assetTitle(),
            $photo->credit !== '' ? $photo->credit : null,
            $photo->creditUrl,
            $photo->editorial ? Offer::EDITORIAL : $offer->licenceType,
            $photo->editorial,
            $photo->restrictions,
            noModelInput: $noModelInput,
        );
    }

    private function may(string $state): bool
    {
        return in_array($state, self::next($this->state), true);
    }

    /**
     * @return array<int, string>
     */
    private static function next(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }

    /**
     * @throws Conflict
     */
    private function require(string $state): void
    {
        if (! $this->may($state)) {
            throw new Conflict("A stock image can't go from {$this->state} to {$state}.");
        }
    }

    private function moveTo(string $state, DateTimeImmutable $now): void
    {
        $this->state = $state;
        $this->stateChangedAt = $now;
    }

    private function addUsage(?Usage $usage, DateTimeImmutable $now, ?Person $by): void
    {
        if ($usage === null) {
            return;
        }

        foreach ($this->usages as $i => $existing) {
            if ($existing->key() === $usage->key()) {
                $this->usages[$i] = $existing->seen($now, $usage->label, $usage->live);

                return;
            }
        }

        $this->usages[] = $usage->seen($now, $usage->label, $usage->live);
        $this->record(HistoryEvent::USAGE_ADDED, $now, $by, ['owner' => $usage->ownerType.':'.$usage->ownerId, 'site' => $usage->site, 'field' => $usage->field]);
    }

    /**
     * @param  array<string, scalar|null>  $detail
     */
    private function record(string $event, DateTimeImmutable $now, ?Person $by, array $detail = []): void
    {
        $this->history[] = HistoryEvent::make($event, $now, $by, $detail);
    }
}
