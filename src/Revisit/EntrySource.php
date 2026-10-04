<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * The entries the revisit index reads, implemented by each addon over its
 * CMS: the published entries of Ghostwriter's groups (Statamic collections,
 * Craft sections, Filament resources), one site at a time or all, in
 * chunks, never all in memory at once. Proven by
 * Tests\Contracts\EntrySourceContract.
 */
interface EntrySource
{
    /**
     * Published entries, one site or every site (null), in chunks.
     *
     * @return iterable<EntrySnapshot>
     */
    public function all(int|string|null $site = null, int $chunk = 200): iterable;

    /**
     * Entries saved after a time: the daily pass's fast path. Published
     * or not: one that was unpublished is forgotten.
     *
     * @return iterable<EntryRef>
     */
    public function updatedSince(DateTimeInterface $since, int|string|null $site = null): iterable;

    /** One entry; null when it's gone. Unpublished entries come back with `published` false. */
    public function find(EntryRef $ref): ?EntrySnapshot;
}
