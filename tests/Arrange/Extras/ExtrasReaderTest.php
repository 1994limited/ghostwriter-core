<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraItem;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSlots;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtrasReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\SourceKind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** No invented facts: an extra item is kept only with a source for every fact in it, or an ask in place of the fact. */
final class ExtrasReaderTest extends TestCase
{
    public const DRAFT = "title: Winter care\nintro: Four visits between November and February, across Northumberland.\n";

    public static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text),
            new Field('page_builder', Kind::Blocks, sets: [
                'stats' => new Set('Stats', '', [new Field('items', Kind::Rows, fields: [new Field('value', Kind::Text), new Field('label', Kind::Text)])]),
                'faq' => new Set('FAQ', '', [new Field('questions', Kind::Rows, fields: [new Field('question', Kind::Text), new Field('answer', Kind::LongText)])]),
                'testimonial' => new Set('Testimonial', '', [new Field('quote', Kind::LongText), new Field('attribution', Kind::Text)]),
                'pull_quote' => new Set('Pull quote', '', [new Field('text', Kind::LongText)]),
            ]),
        ]);
    }

    public static function sources(): ExtraSources
    {
        $conversation = new Conversation(
            [['role' => 'user', 'content' => "Here is the brief.\n\n**Areas**\nNorthumberland, Durham and the Tyne Valley"], ['role' => 'assistant', 'content' => '1. What do clients say?'], ['role' => 'user', 'content' => 'Mrs Hall in Hexham said: we used to clear everything in October.']],
            null,
            ['price' => ''],
        );
        $layout = new Layout('', [['title' => 'Winter round 2025', 'body' => 'We visited 40 gardens last winter.']]);

        return ExtraSources::fromWriter($conversation, self::DRAFT, $layout, [812]);
    }

    private function read(string $yaml, ?ExtrasReader $reader = null): Extras
    {
        return ($reader ?? new ExtrasReader)->read($yaml, ExtraSlots::for(self::schema()), self::sources());
    }

    public function test_a_fact_quoted_from_the_draft_is_kept_with_its_source(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "4 visits a winter"
                  value: "4"
                  label: visits a winter
                  source: { from: draft, quote: "Four visits between November and February" }
                - text: "Areas: Northumberland, Durham, Tyne Valley"
                  source: { from: answer, ref: 2, quote: "Northumberland, Durham and the Tyne Valley" }
                - text: "3 areas: Northumberland, Durham, Tyne Valley"
                  source: { from: answer, ref: 2, quote: "Northumberland, Durham and the Tyne Valley" }
            YAML);

        $item = $extras->item('x1.1');
        $this->assertNotNull($item);
        $this->assertSame(['4 visits a winter', ['value' => '4', 'label' => 'visits a winter']], [$item->text, $item->parts]);
        $this->assertSame(SourceKind::Draft, $item->source?->kind);
        $this->assertFalse($item->needsAnswer());
        $this->assertSame('2', $extras->item('x1.2')?->source?->ref);
        $this->assertSame(ExtraKind::Stats, $extras->extraOf('x1.2.label')?->kind);
        $this->assertSame('[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]: Northumberland, Durham, Tyne Valley', $extras->item('x1.3')?->text, 'a count of the quoted list is core\'s count, to be checked (DerivedCountsTest)');
    }

    public function test_an_invented_number_is_dropped_even_when_the_quote_matched(): void
    {
        $reader = new ExtrasReader;
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "12 visits a winter"
                  source: { from: draft, quote: "Four visits between November and February" }
                - text: "98% of clients renew"
            YAML, $reader);

        $this->assertCount(0, $extras);
        $this->assertSame(['stats item 1: it says more than its quote', 'stats item 2: it has no source'], $reader->dropped);
        $this->assertSame(['Smith', 'Morpeth'], ExtrasReader::unnamed('Jane Smith, Morpeth', 'Jane said so'));
        $this->assertSame([], ExtrasReader::unnamed('A happy client, Hexham', 'in Hexham'));
    }

    public function test_a_quote_that_is_not_in_its_source_drops_the_item(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "4 visits a winter"
                  source: { from: brief, quote: "Four visits between November and February" }
                - text: "4 visits a winter"
                  source: { from: draft, quote: "Five visits between November and February" }
                - text: "4 visits a winter"
                  source: { from: editor, quote: "Four visits between November and February" }
            YAML);

        $this->assertCount(0, $extras, 'the draft is not the brief, a changed quote is not a quote, and only the editor is the editor');
    }

    public function test_an_ask_takes_the_place_of_a_missing_fact(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "Visits from [[ask: price per visit]]"
                - text: "£60 a visit, or [[ask: price per visit]]"
                - text: "Visits from [[ask: price per visit]]"
                  source: { from: draft, quote: "Nothing like this" }
            YAML);

        $items = array_values($extras->items());
        $this->assertCount(2, $items, 'an ask beside an invented price is still an invented price');
        $this->assertTrue($items[0]->needsAnswer());
        $this->assertSame(['price per visit'], $items[0]->askHints);
        $this->assertNull($items[1]->source, 'a failed source falls back on the ask');
    }

    public function test_a_testimonial_needs_its_speaker_in_the_source(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: testimonial
              items:
                - text: "We used to clear everything in October."
                  attribution: "Mrs Hall, Hexham"
                  source: { from: answer, ref: 1, quote: "we used to clear everything in October" }
                - text: "We used to clear everything in October."
                  attribution: "Jane Smith, Morpeth"
                  source: { from: answer, ref: 1, quote: "we used to clear everything in October" }
                - text: "Best gardeners in the North East!"
                  attribution: "A happy client"
            YAML);

        $this->assertSame(['x1.1'], array_keys($extras->items()));
        $this->assertSame('Mrs Hall, Hexham', $extras->item('x1.1')?->part('attribution'));
    }

    public function test_an_existing_entry_is_a_source_with_its_id_and_title(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "40 gardens last winter"
                  source: { from: entry, ref: 1, quote: "We visited 40 gardens last winter" }
                - text: "50 gardens last winter"
                  source: { from: entry, ref: 2, quote: "We visited 50 gardens last winter" }
            YAML);

        $source = $extras->item('x1.1')?->source;
        $this->assertSame([SourceKind::Entry, '1', 812, 'Winter round 2025'], [$source?->kind, $source?->ref, $source?->entryId, $source?->entryTitle]);
        $this->assertSame(['x1.1'], array_keys($extras->items()), 'only an entry the writer was shown counts');
    }

    public function test_kinds_the_site_cannot_show_and_second_extras_of_a_kind_are_dropped(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: cta
              items: [{ text: "Book a visit" }]
            - kind: faq
              items:
                - question: How often do you visit?
                  text: Four times between November and February.
                  source: { from: draft, quote: "Four visits between November and February" }
                - text: An answer with no question.
                  source: { from: draft, quote: "Four visits" }
            - kind: faq
              items:
                - question: Where?
                  text: Northumberland.
                  source: { from: draft, quote: "across Northumberland" }
            - kind: made_up
              items: [{ text: Hi }]
            YAML);

        $this->assertSame(['x1'], array_map(fn ($extra) => $extra->id, $extras->all()));
        $this->assertSame(['x1.1'], array_keys($extras->items()));
        $this->assertSame('How often do you visit?', $extras->item('x1.1.question')?->part('question'));
    }

    public function test_a_link_is_never_added(): void
    {
        $this->assertCount(0, $this->read(<<<'YAML'
            - kind: pull_quote
              items:
                - text: "Four visits between November and February: https://example.org/book"
                  source: { from: draft, quote: "Four visits between November and February" }
            YAML));
    }

    public function test_an_unreadable_block_is_left_out_and_logged(): void
    {
        $logs = [];
        $logger = new class($logs) extends AbstractLogger
        {
            /** @param list<string> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = (string) $message;
            }
        };

        $this->assertCount(0, (new ExtrasReader($logger))->read("- kind: stats\n  items: [\n", ExtraSlots::for(self::schema()), self::sources()));
        $this->assertCount(0, (new ExtrasReader)->read('kind: stats', ExtraSlots::for(self::schema()), self::sources()));
        $this->assertCount(0, (new ExtrasReader)->read(null, ExtraSlots::for(self::schema()), self::sources()));
        $this->assertSame(["Ghostwriter: the writer's extras could not be read and were left out."], $logs);
    }

    public function test_extras_round_trip_and_the_editor_can_change_or_delete_an_item(): void
    {
        $extras = $this->read(<<<'YAML'
            - kind: stats
              items:
                - text: "4 visits a winter"
                  source: { from: draft, quote: "Four visits between November and February" }
                - text: "Visits from [[ask: price per visit]]"
            YAML);

        $this->assertEquals($extras, Extras::fromArray($extras->toArray()));

        $edited = $extras->edit('x1.2', 'Visits from £60');
        $item = $edited->item('x1.2');
        $this->assertInstanceOf(ExtraItem::class, $item);
        $this->assertSame([SourceKind::Editor, 'Visits from £60', false], [$item->source?->kind, $item->source?->quote, $item->needsAnswer()]);

        $this->assertSame(['x1.1'], array_keys($edited->without('x1.2')->items()));
        $this->assertCount(0, $edited->without('x1.2')->without('x1.1'));
    }
}
