<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraItem;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSlots;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtrasReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\SourceKind;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use PHPUnit\Framework\TestCase;

/** A count of a list is counted by core, never by the model, and marked for the editor to check. */
final class DerivedCountsTest extends TestCase
{
    private function read(string $yaml, ?ExtrasReader $reader = null): Extras
    {
        return ($reader ?? new ExtrasReader)->read($yaml, ExtraSlots::for(ExtrasReaderTest::schema()), ExtrasReaderTest::sources());
    }

    public function test_a_count_of_a_quoted_list_is_cores_count_marked_for_review(): void
    {
        $reader = new ExtrasReader;
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - value: "3"
                  label: areas
                  source: { from: answer, quote: "Northumberland, Durham and the Tyne Valley" }
            YAML, $reader);

        $item = $extras->item('x1.1');
        $this->assertInstanceOf(ExtraItem::class, $item);
        $this->assertSame('[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]', $item->text);
        $this->assertSame(['value' => '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', 'label' => 'areas'], $item->parts);
        $this->assertSame(['Northumberland', 'Durham', 'the Tyne Valley'], $item->count?->items);
        $this->assertSame('Northumberland, Durham and the Tyne Valley', $item->count->text);
        $this->assertSame(SourceKind::Answer, $item->source?->kind);
        $this->assertTrue($item->needsReview());
        $this->assertFalse($item->needsAnswer());
        $this->assertSame('Counted from your answer: “Northumberland, Durham and the Tyne Valley”', $item->countLabel()?->english());
        $this->assertSame('Needs review', $item->state()?->english());
        $this->assertSame(['stats item 1: counted 3 in its quote'], $reader->counted);
        $this->assertEquals($extras, Extras::fromArray($extras->toArray()), 'the count is kept with the session');
    }

    public function test_a_count_the_model_got_wrong_is_replaced_by_cores(): void
    {
        $reader = new ExtrasReader;
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "Gardens in five areas"
                  source: { from: answer, quote: "Northumberland, Durham and the Tyne Valley" }
            YAML, $reader);

        $this->assertSame('Gardens in [[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]', $extras->item('x1.1')?->text);
        $this->assertSame(['stats item 1: counted 3 in its quote (it said 5)'], $reader->counted);
    }

    public function test_what_is_not_a_plain_list_or_says_more_is_still_dropped(): void
    {
        $reader = new ExtrasReader;
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "3 areas and 40 gardens"
                  source: { from: answer, quote: "Northumberland, Durham and the Tyne Valley" }
                - text: "2 places"
                  source: { from: answer, quote: "Durham and the Tyne Valley" }
                - text: "3 areas, all in the North East"
                  source: { from: answer, quote: "Northumberland, Durham and the Tyne Valley" }
            YAML, $reader);

        $this->assertCount(0, $extras);
        $this->assertSame([], $reader->counted);
        $this->assertCount(3, $reader->dropped);
    }

    public function test_an_item_the_editor_edits_keeps_its_count_while_the_marker_is_there(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "3 areas"
                  source: { from: answer, quote: "Northumberland, Durham and the Tyne Valley" }
            YAML);
        $marked = (string) $extras->item('x1.1')?->text;

        $kept = $extras->edit('x1.1', 'Across '.$marked)->item('x1.1');
        $this->assertSame([SourceKind::Answer, 3, true], [$kept?->source?->kind, $kept?->count?->count(), $kept?->needsReview()]);

        $confirmed = $extras->edit('x1.1', Markers::withoutChecks($marked))->item('x1.1');
        $this->assertSame([SourceKind::Editor, null, false, null], [$confirmed?->source?->kind, $confirmed?->count, $confirmed?->needsReview(), $confirmed?->state()]);
    }

    public function test_a_sessions_sources_are_its_messages_answers_and_draft(): void
    {
        $session = Session::start(Format::Statamic, 'service', ['areas' => 'Northumberland, Durham and the Tyne Valley']);
        $session->messages = [['role' => 'user', 'content' => 'Winter care.'], ['role' => 'assistant', 'content' => 'Here it is.']];
        $session->draft = "title: Winter care\n";

        $this->assertSame(['Winter care.', 'Northumberland, Durham and the Tyne Valley', "title: Winter care\n"], ExtraSources::fromSession($session)->all());
    }
}
