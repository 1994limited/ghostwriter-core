<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\MemoryEntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntrySourceContractTest;

final class MemoryEntrySourceContractTest extends EntrySourceContractTest
{
    protected function entrySource(): EntrySource
    {
        return (new MemoryEntrySource)
            ->put(Fixtures::snapshot(new EntryRef('pages', 'old', 'default'), 'Old page', '2024-03-14'))
            ->put(Fixtures::snapshot(new EntryRef('pages', 'recent', 'default'), 'Recent page', '2026-10-01'))
            ->put(Fixtures::snapshot(new EntryRef('pages', 'draft', 'default'), 'Draft', '2026-10-01', published: false))
            ->put(Fixtures::snapshot(new EntryRef('pages', 'old', 'cy'), 'Hen dudalen', '2024-03-14'));
    }

    protected function sourceEntries(): array
    {
        return [
            'old' => new EntryRef('pages', 'old', 'default'),
            'recent' => new EntryRef('pages', 'recent', 'default'),
            'draft' => new EntryRef('pages', 'draft', 'default'),
            'other' => new EntryRef('pages', 'old', 'cy'),
            'site' => 'default',
            'between' => new DateTimeImmutable('2026-01-01'),
        ];
    }
}
