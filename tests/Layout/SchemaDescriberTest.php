<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

class SchemaDescriberTest extends LayoutTestCase
{
    public function test_with_nothing_published_every_block_is_described(): void
    {
        $this->assertSame(<<<'TEXT'
            - `title` (short text, required)
            - `summary` (plain text). Two sentences
            - `tone` (one of warm, dry)
            - `body` (list of blocks)
              Each block is written as `type: <block>` followed by that block's fields. Blocks:
              - `hero`: Hero. The top of the page
                  - `heading` (short text, required)
                  - `background` (one of light, dark)
              - `text`: Text
                  - `copy` (markdown)
              - `quote`: Quote
                  - `quote` (plain text)
                  - `person` (short text)
              - `spacer`: Spacer
                  - `height` (number)
              - `gallery`: Gallery
            TEXT, (new SchemaDescriber)->describe(self::articles()));
    }

    public function test_the_pattern_narrows_the_brief_to_what_is_used(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), self::entries());

        $this->assertSame(<<<'TEXT'
            - `title` (short text, required)
            - `summary` (plain text). Two sentences
            - `tone` (one of warm, dry)
            - `body` (list of blocks)
              Each block is written as `type: <block>` followed by that block's fields. Blocks:
              - `hero`: Hero. The top of the page, on 100% of entries
                  - `heading` (short text, required)
              - `text`: Text, on 100% of entries
                  - `copy` (markdown)
              - `quote`: Quote, on 100% of entries
                  (the same on every entry; write `type: quote` and nothing else)
              - `spacer`: Spacer, on 100% of entries
                  (the same on every entry; write `type: spacer` and nothing else)
              Also available but not normally used here: `gallery` (Gallery).

            Entries in this collection usually build `body` from these blocks, in this order: hero, text, quote, spacer. Follow that order unless the brief gives a reason not to.
            These blocks are the same on every entry. Include each with its `type` alone, in its usual place, and its content is copied in for you. Never ask for their text: quote, spacer.
            TEXT, (new SchemaDescriber(LayoutOptions::statamic()))->describe(self::articles(), $pattern));

        // The same from the addons' array, and Craft and Filament say "section".
        $this->assertStringContainsString('Entries in this section usually build', (new SchemaDescriber)->describe(self::articles(), $pattern->toArray()));
    }

    public function test_house_defaults_for_text_are_shown_as_usual(): void
    {
        $pattern = ['blocks' => ['body' => ['sequence' => [], 'usage' => ['quote' => 1.0], 'fixed' => ['quote' => ['person' => 'Priya']], 'used' => ['quote' => ['quote', 'person']], 'boilerplate' => []]]];

        $this->assertStringContainsString("- `quote` (plain text)\n      - `person` (short text). Usually \"Priya\"", (new SchemaDescriber)->describe(self::articles(), $pattern));
    }

    public function test_neo_child_blocks_are_explained(): void
    {
        $schema = new Schema([new Field('builder', Kind::Blocks, sets: [
            'hero' => new Set('Hero', '', [new Field('children', Kind::Blocks, 'Blocks inside', engine: Field::CHILDREN, sets: ['text' => new Set('Text', '', [new Field('copy', Kind::RichText)])])]),
        ])]);

        $this->assertStringContainsString("followed by that block's fields; a block that holds other blocks lists them under `children`. Blocks:", (new SchemaDescriber)->describe($schema));
    }

    public function test_rows_and_groups_list_their_fields_and_labels_that_say_more_than_the_handle_are_kept(): void
    {
        $schema = new Schema([
            new Field('faqs', Kind::Rows, 'Questions', fields: [new Field('question', Kind::Text, 'Question'), new Field('answer', Kind::LongText)]),
            new Field('seo_title', Kind::Text, 'SEO title'),
            new Field('meta', Kind::Group, fields: [new Field('tags', Kind::List), new Field('image', Kind::Reference)]),
            new Field('featured', Kind::Toggle),
            new Field('topics', Kind::Choices, options: ['a' => 'A', 'b' => 'B']),
        ]);

        $this->assertSame(<<<'TEXT'
            - `faqs` (list of rows): Questions
              - `question` (short text)
              - `answer` (plain text)
            - `seo_title` (short text)
            - `meta` (group)
              - `tags` (list of short strings)
            - `featured` (true or false)
            - `topics` (any of a, b)
            TEXT, (new SchemaDescriber)->describe($schema));
    }
}
