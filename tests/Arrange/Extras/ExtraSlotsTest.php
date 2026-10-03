<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSlots;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use PHPUnit\Framework\TestCase;

final class ExtraSlotsTest extends TestCase
{
    public function test_a_page_builder_offers_the_kinds_its_sets_are_for(): void
    {
        $schema = new Schema([
            new Field('title', Kind::Text),
            new Field('excerpt', Kind::LongText, 'Excerpt'),
            new Field('blocks', Kind::Blocks, sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text)]),
                'numbers' => new Set('Our numbers', '', [new Field('rows', Kind::Rows, fields: [new Field('value', Kind::Text), new Field('label', Kind::Text)])]),
                'grid' => new Set('Grid', '', [new Field('cells', Kind::Rows, fields: [new Field('number', Kind::Text), new Field('caption', Kind::Text)])]),
                'accordion' => new Set('Accordion', '', [new Field('items', Kind::Rows)]),
                'pullQuote' => new Set('Pull Quote', '', [new Field('text', Kind::LongText)]),
                'callToAction' => new Set('Call to action', '', [new Field('heading', Kind::Text)]),
                'image' => new Set('Image', '', [new Field('image', Kind::Reference, files: true), new Field('caption', Kind::Text)]),
                'key_facts' => new Set('Key facts', '', [new Field('items', Kind::List)]),
            ]),
        ]);

        $slots = ExtraSlots::for($schema);

        $this->assertSame([ExtraKind::Stats, ExtraKind::Faq, ExtraKind::PullQuote, ExtraKind::AtAGlance, ExtraKind::Caption, ExtraKind::Cta, ExtraKind::Intro], $slots->kinds());
        $this->assertSame(['numbers', 'grid'], array_column($slots->slots(ExtraKind::Stats), 'set'), 'by its name, or by rows of numbers and labels');
        $this->assertSame([['field' => 'excerpt', 'set' => null, 'label' => 'Excerpt']], $slots->slots(ExtraKind::Intro));
        $this->assertStringContainsString('- `pull_quote`: one sentence worth setting large', $slots->describe());
        $this->assertStringContainsString('It would go in the “Pull Quote” block.', $slots->describe());
        $this->assertFalse($slots->has(ExtraKind::Testimonial));
    }

    public function test_rich_text_offers_what_markdown_can_hold(): void
    {
        $slots = ExtraSlots::for(new Schema([
            new Field('title', Kind::Text),
            new Field('body', Kind::RichText, 'Body', sets: ['quote' => new Set('Quote', '', [new Field('text', Kind::LongText), new Field('cite', Kind::Text)])]),
        ]));

        $this->assertSame([ExtraKind::Faq, ExtraKind::PullQuote, ExtraKind::AtAGlance, ExtraKind::Testimonial, ExtraKind::Intro], $slots->kinds());
    }

    public function test_no_schema_or_nothing_to_hold_an_extra_offers_nothing(): void
    {
        $this->assertTrue(ExtraSlots::for(null)->isEmpty());
        $this->assertTrue(ExtraSlots::for(new Schema([new Field('title', Kind::Text), new Field('notes', Kind::LongText)]))->isEmpty());
        $this->assertSame('', ExtraSlots::for(null)->describe());
    }
}
