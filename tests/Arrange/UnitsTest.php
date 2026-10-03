<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class UnitsTest extends TestCase
{
    public const BODY = "Winter is when a garden is set up for the year.\n\n## What the visits are\n\n**November: Cut back.** Prune the shrubs that need it.\n\n### In detail\n\nWrap the tender plants.\n\n## Who it suits\n\nGardens with mixed borders.\n\n- Lawns\n- Gravel";

    public static function schema(string $richType = ''): Schema
    {
        $rich = new Field('text', Kind::RichText, 'Text', type: $richType);

        return new Schema([
            new Field('title', Kind::Text, 'Title'),
            new Field('intro', Kind::LongText, 'Intro'),
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text, 'Heading'), new Field('image', Kind::Reference, 'Image', files: true)]),
                'text' => new Set('Text', '', [$rich]),
                'faq' => new Set('FAQ', '', [new Field('questions', Kind::Rows, 'Questions', fields: [new Field('question', Kind::Text), new Field('answer', Kind::LongText)])]),
                'ticks' => new Set('Ticks', '', [new Field('items', Kind::List, 'Items')]),
            ]),
            new Field('notes', Kind::LongText, 'Notes', type: 'markdown'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function draft(): array
    {
        return [
            'title' => 'Winter care',
            'intro' => 'Four visits between November and February.',
            'page_builder' => [
                ['type' => 'hero', 'heading' => 'Winter care visits', 'image' => 'assets::garden.jpg'],
                ['type' => 'text', 'text' => self::BODY],
                ['type' => 'faq', 'questions' => [['question' => 'How often?', 'answer' => 'Four times.'], ['question' => '', 'answer' => '']]],
                ['type' => 'ticks', 'items' => ['Pruning', 'Mulching']],
            ],
            'notes' => "> Our clients say it's worth it.",
        ];
    }

    public function test_a_draft_in_reading_order(): void
    {
        $units = Units::fromDraft(Draft::parse(Yaml::dump(self::draft(), 6)), self::schema());

        $this->assertSame(
            [
                ['u1', 'text', 'title', null, 'Winter care'],
                ['u2', 'prose', 'intro', null, 'Four visits between November and February.'],
                ['u3', 'text', 'page_builder/0/heading', null, 'Winter care visits'],
                ['u4', 'media', 'page_builder/0/image', null, ''],
                ['u5', 'prose', 'page_builder/1/text', 0, 'Winter is when a garden is set up for the year.'],
                ['u6', 'section', 'page_builder/1/text', 1, "## What the visits are\n\n**November: Cut back.** Prune the shrubs that need it.\n\n### In detail\n\nWrap the tender plants."],
                ['u7', 'section', 'page_builder/1/text', 2, "## Who it suits\n\nGardens with mixed borders.\n\n- Lawns\n- Gravel"],
                ['u8', 'row', 'page_builder/2/questions/0', null, "How often?\n\nFour times."],
                ['u9', 'list', 'page_builder/3/items', null, "Pruning\nMulching"],
                ['u10', 'quote', 'notes', 0, "> Our clients say it's worth it."],
            ],
            array_map(fn (Unit $unit) => [$unit->id, $unit->kind->value, $unit->path->toString(), $unit->part, $unit->markdown], $units->all()),
        );
        $this->assertSame(11, $units->next);
        $this->assertSame('hero', $units->get('u3')?->blockType);
        $this->assertSame(['assets::garden.jpg'], $units->get('u4')?->assets);
        $this->assertSame(
            [[Piece::HEADING, 2], [Piece::PARAGRAPH, 0], [Piece::HEADING, 3], [Piece::PARAGRAPH, 0]],
            array_map(fn (Piece $piece) => [$piece->kind, $piece->level], $units->get('u6')->pieces ?? []),
        );
        $this->assertSame([Piece::HEADING, Piece::PARAGRAPH, Piece::ITEM, Piece::ITEM], array_map(fn (Piece $piece) => $piece->kind, $units->get('u7')->pieces ?? []));
        $this->assertSame(['question', 'answer'], array_map(fn (Piece $piece) => $piece->field, $units->get('u8')->pieces ?? []));
        $this->assertSame(['u5', 'u6', 'u7'], array_map(fn (Unit $unit) => $unit->id, $units->inBlock(FieldPath::parse('page_builder/1'))));
        $this->assertSame(['u5', 'u6', 'u7'], array_map(fn (Unit $unit) => $unit->id, $units->at(FieldPath::parse('page_builder/1/text'))));
    }

    public function test_rich_text_with_no_headings_is_one_unit(): void
    {
        $schema = new Schema([new Field('body', Kind::RichText)]);

        $this->assertSame(UnitKind::Prose, Units::fromDraft(['body' => "One.\n\nTwo."], $schema)->all()[0]->kind);
        $this->assertSame(UnitKind::List, Units::fromDraft(['body' => "- One\n- Two\n  more"], $schema)->all()[0]->kind);
        $this->assertSame(UnitKind::Quote, Units::fromDraft(['body' => "> One\n> Two"], $schema)->all()[0]->kind);
        $this->assertCount(1, Units::fromDraft(['body' => "```\n# not a heading\n```"], $schema));
    }

    public function test_an_entry_in_html_has_the_same_units_with_block_ids(): void
    {
        $dialect = new HtmlDialect;
        $field = new Field('text', Kind::RichText);
        $values = self::draft();
        $values['page_builder'][1]['text'] = $dialect->fromMarkdown(self::BODY, $field);

        foreach ($values['page_builder'] as $i => $block) {
            $values['page_builder'][$i]['id'] = 'b'.$i;
        }

        $this->assertSameShape(Units::fromEntry(new EntryData($values), self::schema(), $dialect), '#b1');
    }

    public function test_an_entry_in_bard_has_the_same_units(): void
    {
        $dialect = new BardDialect;
        $values = self::draft();
        $values['page_builder'][1]['text'] = $dialect->fromMarkdown(self::BODY, new Field('text', Kind::RichText, type: 'bard'));

        $this->assertIsArray($values['page_builder'][1]['text']);
        $this->assertSameShape(Units::fromEntry(new EntryData($values), self::schema('bard'), $dialect), '1');
    }

    public function test_ids_round_trip_through_the_sidecar(): void
    {
        $units = Units::fromDraft(self::draft(), self::schema());
        $sidecar = $units->sidecar();

        $this->assertSame(['path' => 'page_builder/1/text', 'part' => 1, 'kind' => 'section', 'hash' => $units->get('u6')?->hash()], $sidecar['units']['u6']);

        // Stored ids that aren't u1… (they were carried) come back at their places.
        $renamed = ['next' => 40, 'units' => []];

        foreach ($sidecar['units'] as $id => $entry) {
            $renamed['units']['u'.(Units::number($id) + 20)] = $entry;
        }

        unset($renamed['units']['u22']);
        $restored = $units->restore($renamed);

        $this->assertSame('u21', $restored->all()[0]->id);
        $this->assertSame('u40', $restored->all()[1]->id, 'a place the sidecar does not have gets a new id');
        $this->assertSame('u23', $restored->all()[2]->id);
        $this->assertSame(41, $restored->next);
        $this->assertSame($units, $units->restore([]));
        $this->assertSame($units->toArray(), Units::fromArray($units->toArray())->toArray(), 'block types aside, which the path string leaves out');
    }

    public function test_the_hash_ignores_whitespace_and_quote_styles(): void
    {
        $a = new Unit('u1', UnitKind::Prose, FieldPath::of('body'), 'We’ll  say so.');
        $b = new Unit('u1', UnitKind::Prose, FieldPath::of('body'), "We'll say\nso.");

        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), (new Unit('u1', UnitKind::Prose, FieldPath::of('body'), 'We will say so.'))->hash());
    }

    private function assertSameShape(Units $units, string $block): void
    {
        $draft = Units::fromDraft(self::draft(), self::schema());

        $this->assertSame(
            array_map(fn (Unit $unit) => [$unit->id, $unit->kind, $unit->path->dotted(), $unit->part], $draft->all()),
            array_map(fn (Unit $unit) => [$unit->id, $unit->kind, $unit->path->dotted(), $unit->part], $units->all()),
        );
        $this->assertSame("page_builder/{$block}/text", $units->get('u6')?->path->toString());
        $this->assertSame(UnitMatcherTest::words($draft->get('u6')?->markdown ?? ''), UnitMatcherTest::words($units->get('u6')?->markdown ?? ''));
    }
}
