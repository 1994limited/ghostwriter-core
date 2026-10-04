<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * A RevisitStore in memory, for tests and the demo, and the reference for
 * what the port must do (RevisitStoreContract). Rows are kept as arrays,
 * as a real store keeps them, so a round trip is tested too.
 */
final class InMemoryRevisitStore implements RevisitStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function put(RevisitRow $row): void
    {
        $this->rows[$row->entry->key()] = $row->toArray();
    }

    public function get(EntryRef $entry): ?RevisitRow
    {
        return isset($this->rows[$entry->key()]) ? RevisitRow::fromArray($this->rows[$entry->key()]) : null;
    }

    public function forget(EntryRef $entry): void
    {
        unset($this->rows[$entry->key()]);
    }

    public function top(int|string|null $site, ?string $group = null, int $limit = 25, int $offset = 0, array $kinds = [], ?DateTimeImmutable $now = null): array
    {
        $rows = $this->listed($site, $group, $kinds, $now);
        usort($rows, fn (RevisitRow $a, RevisitRow $b) => [$b->score, $a->title] <=> [$a->score, $b->title]);

        return array_slice($rows, $offset, $limit);
    }

    public function count(int|string|null $site, ?string $group = null, array $kinds = [], ?DateTimeImmutable $now = null): int
    {
        return count($this->listed($site, $group, $kinds, $now));
    }

    public function stats(int|string|null $site, ?DateTimeImmutable $now = null): array
    {
        $stats = ['worth-a-look' => 0];

        foreach ($this->listed($site, null, [], $now) as $row) {
            if ($row->score >= Priority::WORTH_A_LOOK) {
                $stats['worth-a-look']++;
            }

            foreach (array_unique(array_map(fn (RevisitReason $reason) => $reason->kind->value, $row->reasons)) as $kind) {
                $stats[$kind] = ($stats[$kind] ?? 0) + 1;
            }
        }

        return $stats;
    }

    public function linkingTo(string $target, int|string|null $site = null): array
    {
        $found = [];

        foreach ($this->all($site) as $row) {
            if (in_array($target, $row->linksTo, true)) {
                $found[] = $row->entry;
            }
        }

        return $found;
    }

    public function all(int|string|null $site = null): iterable
    {
        foreach ($this->rows as $row) {
            $row = RevisitRow::fromArray($row);

            if ($site === null || (string) $row->entry->site === (string) $site) {
                yield $row;
            }
        }
    }

    /**
     * @param  array<int, string>  $kinds
     * @return list<RevisitRow>
     */
    private function listed(int|string|null $site, ?string $group, array $kinds, ?DateTimeImmutable $now): array
    {
        $now ??= new DateTimeImmutable;
        $rows = [];

        foreach ($this->all($site) as $row) {
            if ($row->score <= 0 || $row->isSnoozed($now) || ($group !== null && $row->entry->group !== $group)) {
                continue;
            }

            if ($kinds !== [] && array_intersect($kinds, array_map(fn (RevisitReason $reason) => $reason->kind->value, $row->reasons)) === []) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
