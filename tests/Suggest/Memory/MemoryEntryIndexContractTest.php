<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EntryIndexContractTest;

final class MemoryEntryIndexContractTest extends EntryIndexContractTest
{
    private ?MemoryEntryIndex $index = null;

    protected function entryIndex(): EntryIndex
    {
        if ($this->index === null) {
            $refs = $this->indexedEntries();
            $this->index = (new MemoryEntryIndex)
                ->add($refs['design'], 'Garden design', [self::DESIGN], '/garden-design', 'Design for whole gardens.')
                ->add($refs['planting'], 'Planting plans', ['Short.'], '/planting-plans', 'Planting plans for borders, pots and new gardens.')
                ->add($refs['journal'], 'A walled garden in Corbridge', ['A two-year look at a walled garden we designed in Corbridge, with notes on what thrived and what we moved.'], '/journal/corbridge', 'A two-year look.')
                ->add($refs['other'], 'Garden design', [self::DESIGN], '/cy/garden-design', 'Design for whole gardens.');
        }

        return $this->index;
    }

    protected function indexedEntries(): array
    {
        return [
            'design' => new EntryRef('pages', 'design', 'default'),
            'planting' => new EntryRef('pages', 'planting', 'default'),
            'journal' => new EntryRef('journal', 'corbridge', 'default'),
            'other' => new EntryRef('pages', 'design', 'cy'),
        ];
    }
}
