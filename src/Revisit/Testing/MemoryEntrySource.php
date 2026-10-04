<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing;

use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * Entries held in memory, for tests and the demo, and the reference for
 * what the port must do (EntrySourceContract).
 */
final class MemoryEntrySource implements EntrySource
{
    /** @var array<string, EntrySnapshot> */
    private array $entries = [];

    /** @var array<int, int> The chunk sizes all() was asked for. */
    public array $chunks = [];

    public function put(EntrySnapshot $snapshot): self
    {
        $this->entries[$snapshot->ref->key()] = $snapshot;

        return $this;
    }

    public function remove(EntryRef $ref): void
    {
        unset($this->entries[$ref->key()]);
    }

    public function all(int|string|null $site = null, int $chunk = 200): iterable
    {
        $this->chunks[] = $chunk;

        foreach (array_chunk($this->entries, max(1, $chunk)) as $batch) {
            foreach ($batch as $snapshot) {
                if ($snapshot->published && ($site === null || (string) $snapshot->ref->site === (string) $site)) {
                    yield $snapshot;
                }
            }
        }
    }

    public function updatedSince(DateTimeInterface $since, int|string|null $site = null): iterable
    {
        foreach ($this->entries as $snapshot) {
            $updated = $snapshot->context->updatedAt;

            if ($updated !== null && $updated > $since && ($site === null || (string) $snapshot->ref->site === (string) $site)) {
                yield $snapshot->ref;
            }
        }
    }

    public function find(EntryRef $ref): ?EntrySnapshot
    {
        return $this->entries[$ref->key()] ?? null;
    }
}
