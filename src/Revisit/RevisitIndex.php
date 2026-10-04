<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * Keeps the Content to revisit list current, with no model:
 *
 * - **On save** (each addon's save hook, queued and deduplicated):
 *   refreshOne() scans that entry again.
 * - **On delete:** deleted() forgets it and scans again every entry whose
 *   links held it, so a newly broken link shows at once.
 * - **Daily:** refresh() scans entries saved since the last run, and rows
 *   whose watch date has come (a closing date passed, a new year); every
 *   other row is only re-scored for its age. `full` scans everything (the
 *   weekly pass, and the first run after install).
 *
 *     $index = new RevisitIndex(new RevisitScanner($agePolicy, ownHosts: ['northfold.co.uk']), $store);
 *     $index->refreshOne($source, $ref, new DateTimeImmutable);
 */
final class RevisitIndex
{
    public function __construct(
        private readonly RevisitScanner $scanner,
        private readonly RevisitStore $store,
    ) {}

    /**
     * The daily pass. Returns how many entries were scanned (not merely
     * re-scored).
     */
    public function refresh(EntrySource $source, DateTimeImmutable $now, ?DateTimeImmutable $lastRun = null, int|string|null $site = null, bool $full = false): int
    {
        if ($full || $lastRun === null) {
            $seen = [];
            $scanned = 0;

            foreach ($source->all($site) as $snapshot) {
                $seen[$snapshot->ref->key()] = true;

                if ($snapshot->published) {
                    $this->store->put($this->scanner->scan($snapshot, $now, $this->store->get($snapshot->ref)));
                    $scanned++;
                } else {
                    $this->store->forget($snapshot->ref);
                }
            }

            foreach ($this->rows($site) as $row) {
                if (! isset($seen[$row->entry->key()])) {
                    $this->store->forget($row->entry);
                }
            }

            return $scanned;
        }

        $done = [];

        foreach ($source->updatedSince($lastRun, $site) as $ref) {
            $this->refreshOne($source, $ref, $now);
            $done[$ref->key()] = true;
        }

        $today = $now->format('Y-m-d');

        foreach ($this->rows($site) as $row) {
            if (isset($done[$row->entry->key()])) {
                continue;
            }

            $due = array_filter($row->watch, fn (string $day) => $day <= $today) !== [];

            if ($due) {
                $this->refreshOne($source, $row->entry, $now);
                $done[$row->entry->key()] = true;

                continue;
            }

            $rescored = $this->scanner->rescore($row, $now);

            if ($rescored->score !== $row->score || $rescored->reasons != $row->reasons) {
                $this->store->put($rescored);
            }
        }

        return count($done);
    }

    /** One entry scanned again: after a save, or when something it links to was deleted. */
    public function refreshOne(EntrySource $source, EntryRef $entry, DateTimeImmutable $now): ?RevisitRow
    {
        $snapshot = $source->find($entry);

        if ($snapshot === null || ! $snapshot->published) {
            $this->store->forget($entry);

            return null;
        }

        $row = $this->scanner->scan($snapshot, $now, $this->store->get($entry));
        $this->store->put($row);

        return $row;
    }

    /**
     * An entry (or asset) was deleted: forget its row, and scan again
     * every entry whose links held one of `$targets`, the forms a link to
     * it is stored in (`entry::abc`, `{entry:12@1:url}`).
     *
     * @param  array<int, string>  $targets
     * @return list<EntryRef> The entries scanned again.
     */
    public function deleted(EntrySource $source, EntryRef $entry, DateTimeImmutable $now, array $targets = []): array
    {
        $this->store->forget($entry);
        $again = [];

        foreach ($targets as $target) {
            foreach ($this->store->linkingTo($target, $entry->site) as $linker) {
                if (! isset($again[$linker->key()]) && ! $linker->is($entry)) {
                    $this->refreshOne($source, $linker, $now);
                    $again[$linker->key()] = $linker;
                }
            }
        }

        return array_values($again);
    }

    /**
     * @return list<RevisitRow>
     */
    private function rows(int|string|null $site): array
    {
        $rows = [];

        foreach ($this->store->all($site) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}
