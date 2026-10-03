<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\CountedList;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\ListCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Core counts lists, never a model: what is a plain list, and what is skipped. */
final class ListCounterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>|null, 2?: string}>
     */
    public static function cases(): array
    {
        return [
            // Inline lists.
            'the brief\'s areas' => ['Northumberland, Durham and the Tyne Valley', ['Northumberland', 'Durham', 'the Tyne Valley']],
            'an Oxford comma' => ['Northumberland, Durham, and the Tyne Valley', ['Northumberland', 'Durham', 'the Tyne Valley']],
            'or' => ['tea, coffee or juice', ['tea', 'coffee', 'juice'], CountedList::OR],
            'or with an Oxford comma' => ['Mondays, Wednesdays, or Fridays', ['Mondays', 'Wednesdays', 'Fridays'], CountedList::OR],
            'four items' => ['pruning, mulching, planting and clearing leaves', ['pruning', 'mulching', 'planting', 'clearing leaves']],
            'a lead-in' => ['We cover Northumberland, Durham and the Tyne Valley.', ['Northumberland', 'Durham', 'the Tyne Valley']],
            'after a colon' => ['Areas we cover: Northumberland, Durham and the Tyne Valley', ['Northumberland', 'Durham', 'the Tyne Valley']],
            'an "and" inside an item, with an Oxford comma' => ['Durham, Tyne and Wear, and Northumberland', ['Durham', 'Tyne and Wear', 'Northumberland']],
            'markdown emphasis' => ['**Northumberland**, Durham and *the Tyne Valley*', ['Northumberland', 'Durham', 'the Tyne Valley']],
            'numbers' => ['Plans of 1, 2 and 3 visits', ['1', '2', '3 visits']],

            // Bulleted lists.
            'bullets' => ["- Northumberland\n- Durham\n- the Tyne Valley", ['Northumberland', 'Durham', 'the Tyne Valley'], CountedList::BULLETS],
            'numbered' => ["1. Cut back\n2. Mulch\n3. Plant bulbs\n4. Clear leaves", ['Cut back', 'Mulch', 'Plant bulbs', 'Clear leaves'], CountedList::BULLETS],
            'asterisks, after a heading' => ["Areas:\n\n* Northumberland\n* Durham", ['Northumberland', 'Durham'], CountedList::BULLETS],
            'one blank line between items' => ["- Northumberland\n\n- Durham\n\n- Tyne Valley", ['Northumberland', 'Durham', 'Tyne Valley'], CountedList::BULLETS],
            'bullets of whole sentences still count' => ["- We cut everything back in November.\n- We mulch in December.", ['We cut everything back in November', 'We mulch in December'], CountedList::BULLETS],

            // Nested lists: the outer level only.
            'nested bullets' => ["- Northumberland\n  - Hexham\n  - Corbridge\n- Durham\n- Tyne Valley", ['Northumberland', 'Durham', 'Tyne Valley'], CountedList::BULLETS],
            'nested, numbered outside' => ["1. Winter\n   - pruning\n   - mulching\n2. Spring", ['Winter', 'Spring'], CountedList::BULLETS],

            // Open lists: skipped.
            'etc.' => ['Northumberland, Durham, Tyne Valley etc.', null],
            'and so on' => ['roses, tulips, dahlias and so on', null],
            'and more' => ['roses, tulips, dahlias and more', null],
            'such as' => ['areas such as Northumberland, Durham and the Tyne Valley', null],
            'including' => ['Areas including Northumberland, Durham and the Tyne Valley', null],
            'e.g.' => ['e.g. Northumberland, Durham and the Tyne Valley', null],
            'for example' => ['For example: Northumberland, Durham and the Tyne Valley', null],
            'an ellipsis' => ['Northumberland, Durham, Tyne Valley…', null],
            'bullets ending etc.' => ["- Northumberland\n- Durham\n- etc.", null],

            // Ranges, prose and ambiguity: skipped.
            'a range of days' => ['Monday, Wednesday to Friday and Sunday', null],
            'a range of numbers' => ['Visits on the 1st, 3–5th and 9th', null],
            'prose with commas' => ['When it rains, we close and the garden rests', null],
            'prose with a verb' => ['In winter, the beds are bare and the roses rest', null],
            'two items, no comma' => ['salt and pepper', null],
            'no final and' => ['Northumberland, Durham, the Tyne Valley', null],
            'and and or mixed' => ['Northumberland, Durham and Cumbria or the Tyne Valley', null],
            'an "and" inside an item, no Oxford comma' => ['Durham, Tyne and Wear and Northumberland', null],
            'an item too long to be one' => ['Durham, the whole of the lovely green Tyne Valley and its many villages, and Hexham', null],
            'one bullet' => ['- Northumberland', null],
            'plain prose' => ['Four visits between November and February.', null],
            'two lists in one quote' => ['Northumberland, Durham and Cumbria. Roses, tulips and dahlias.', null],
        ];
    }

    /**
     * @param  list<string>|null  $items
     */
    #[DataProvider('cases')]
    public function test_it_counts_plain_lists_and_skips_the_rest(string $quote, ?array $items, string $style = CountedList::AND): void
    {
        $list = ListCounter::count($quote);

        if ($items === null) {
            $this->assertNull($list, 'counted: '.json_encode($list?->items));

            return;
        }

        $this->assertNotNull($list, 'not counted');
        $this->assertSame($items, $list->items);
        $this->assertSame(count($items), $list->count());
        $this->assertSame($style, $list->style);
    }

    public function test_it_keeps_the_list_exactly_as_written(): void
    {
        $this->assertSame('Northumberland, Durham and the Tyne Valley', ListCounter::count('We cover Northumberland, Durham and the Tyne Valley.')?->text);
        $this->assertSame("- Northumberland\n  - Hexham\n- Durham", ListCounter::count("Areas:\n- Northumberland\n  - Hexham\n- Durham\n\nThat's all.")?->text);
        $this->assertSame('Northumberland; Durham', ListCounter::count("- Northumberland\n- Durham")?->oneLine(), 'a bulleted list is one line in a marker');
    }

    public function test_it_finds_every_list_in_a_source(): void
    {
        $brief = "Winter care.\n\n**Areas**\nNorthumberland, Durham and the Tyne Valley\n\nWhat we do:\n- pruning\n- mulching\n- planting";
        $lists = ListCounter::find($brief);

        $this->assertSame([3, 3], array_map(fn (CountedList $list) => $list->count(), $lists));
        $this->assertSame([CountedList::BULLETS, CountedList::AND], array_map(fn (CountedList $list) => $list->style, $lists));
    }

    public function test_a_markers_list_reads_back_as_the_same_list(): void
    {
        foreach (['Northumberland, Durham and the Tyne Valley', "- Northumberland\n- Durham\n- Tyne Valley"] as $source) {
            $list = ListCounter::count($source);
            $this->assertNotNull($list);
            $back = ListCounter::fromMarker($list->oneLine());
            $this->assertNotNull($back);
            $this->assertTrue($back->sameItems($list));
        }

        $this->assertTrue(ListCounter::count('Durham, Northumberland and Tyne Valley')?->sameItems(ListCounter::count('Durham, Northumberland and the Tyne Valley') ?? throw new \LogicException), 'a leading article doesn\'t make another item');
        $this->assertSame(2, ListCounter::count('Durham, Cumbria and Tyne Valley')?->shared(ListCounter::count('Durham, Northumberland and the Tyne Valley') ?? throw new \LogicException));
    }

    public function test_whole_numbers_are_found_in_digits_and_words_but_not_inside_other_figures(): void
    {
        $this->assertSame([[5, '5'], [5, 'five'], [12, 'twelve']], array_map(fn (array $n) => [$n['value'], $n['match']], ListCounter::numbers('5 areas, £5, 10%, 2.5, five visits, 3-4 weeks, x1, twelve months')));
        $this->assertSame(3, ListCounter::numberIn('3 areas'));
        $this->assertNull(ListCounter::numberIn('areas'));
    }
}
