<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * Where the revisit rows are kept, implemented by each addon in its own
 * storage style (Statamic: JSON shards per site and collection; Craft and
 * Filament: a table, with a links table indexed on the target). Proven by
 * Tests\Contracts\RevisitStoreContract.
 */
interface RevisitStore
{
    /** Adds or replaces the entry's row. */
    public function put(RevisitRow $row): void;

    public function get(EntryRef $entry): ?RevisitRow;

    public function forget(EntryRef $entry): void;

    /**
     * Highest score first (then the title), snoozed rows and rows scoring 0
     * left out; only rows with one of `$kinds` (ReasonKind values) when
     * given.
     *
     * @param  array<int, string>  $kinds
     * @return list<RevisitRow>
     */
    public function top(int|string|null $site, ?string $group = null, int $limit = 25, int $offset = 0, array $kinds = [], ?\DateTimeImmutable $now = null): array;

    /**
     * How many rows top() would list in all.
     *
     * @param  array<int, string>  $kinds
     */
    public function count(int|string|null $site, ?string $group = null, array $kinds = [], ?\DateTimeImmutable $now = null): int;

    /**
     * For the tiles, not counting snoozed rows: entries per ReasonKind
     * value, and `worth-a-look` (score ≥ Priority::WORTH_A_LOOK).
     *
     * @return array<string, int>
     */
    public function stats(int|string|null $site, ?\DateTimeImmutable $now = null): array;

    /**
     * Entries whose links hold this target (`entry::abc`, as `linksTo` has it).
     *
     * @return list<EntryRef>
     */
    public function linkingTo(string $target, int|string|null $site = null): array;

    /**
     * Every row, one site or all.
     *
     * @return iterable<RevisitRow>
     */
    public function all(int|string|null $site = null): iterable;
}
