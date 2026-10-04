<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;

class EntryBuilderTest extends LayoutTestCase
{
    public function test_a_draft_becomes_entry_data_with_the_groups_defaults(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), self::entries());

        $built = (new EntryBuilder)->build([
            'title' => "  Rain\n gardens ",
            'summary' => "  Soak it up.\n",
            'tone' => 'Dry',
            'colour' => 'green',
            'body' => [
                ['type' => 'hero', 'heading' => 'Rain gardens', 'background' => 'light'],
                ['type' => 'text', 'copy' => "Plant **deep**.\n\n<script>x</script>"],
                ['type' => 'quote', 'quote' => 'Something else'],
                ['type' => 'carousel'],
            ],
        ], self::articles(), $pattern);

        $this->assertSame('Rain gardens', $built->data['title']);
        $this->assertSame('Soak it up.', $built->data['summary']);
        // The label, written for the key.
        $this->assertSame('dry', $built->data['tone']);
        $this->assertSame([
            ['type' => 'hero', 'enabled' => true, 'heading' => 'Rain gardens', 'background' => 'light'],
            ['type' => 'text', 'enabled' => true, 'copy' => "<p>Plant <strong>deep</strong>.</p>\n&lt;script&gt;x&lt;/script&gt;"],
            ['type' => 'quote', 'enabled' => true, 'quote' => 'Gardens take time.', 'person' => 'Priya'],
        ], $built->data['body']);

        $this->assertSame([
            '"colour" is not a field here and was left out.',
            'Quote is the same on every entry here, so its usual content was used in place of what was drafted.',
            'A block of type "carousel" cannot go in body and was left out.',
        ], $built->notes);
    }

    public function test_references_the_kind_usually_has_are_named_and_defaults_win_over_the_groups(): void
    {
        $pattern = new Pattern(
            blocks: ['body' => ['sequence' => [], 'usage' => [], 'fixed' => [], 'used' => ['hero' => ['heading', 'picture']], 'boilerplate' => []]],
            fixed: ['image' => [['id' => 9, 'src' => 'a.jpg']], 'author' => 4],
        );

        $built = (new EntryBuilder)->build(['tone' => 'loud', 'body' => [['type' => 'hero', 'heading' => 'Hi'], 'stray']], self::articles(), $pattern, ['author' => 7]);

        // The kind's default wins; a copied value loses the IDs of the entry it came from.
        $this->assertSame(['author' => 7, 'image' => [['src' => 'a.jpg']]], array_intersect_key($built->data, ['image' => 1, 'author' => 1]));
        $this->assertArrayNotHasKey('tone', $built->data);
        $this->assertSame([
            '"loud" is not an option for tone and was left out.',
            'A block of type "?" cannot go in body and was left out.',
            'Still to choose by hand: Hero: Picture.',
        ], $built->notes);
    }

    public function test_statamic_gives_new_blocks_and_rows_ids_and_copies_fresh_ones(): void
    {
        $ids = 0;
        $options = LayoutOptions::statamic(function () use (&$ids): string {
            return 'id'.++$ids;
        });
        $schema = new Schema([
            new Field('faqs', Kind::Rows, fields: [new Field('q', Kind::Text)]),
            new Field('tags', Kind::List),
            new Field('meta', Kind::Group, fields: [new Field('seo', Kind::Text), new Field('og', Kind::Reference)]),
            new Field('body', Kind::Blocks, sets: ['steps' => new Set('Steps', '', [new Field('rows', Kind::Rows, fields: [new Field('q', Kind::Text)])])]),
        ]);
        $pattern = new Pattern(blocks: ['body' => ['sequence' => [], 'usage' => [], 'fixed' => ['steps' => ['rows' => [['id' => 'old', 'q' => 'One']]]], 'used' => [], 'boilerplate' => ['steps']]]);

        $built = (new EntryBuilder($options))->build([
            'faqs' => [['q' => 'Why?'], 'not a row'],
            'tags' => ['a', 2, ['no']],
            'meta' => ['seo' => 'Title', 'og' => 'x'],
            'body' => [['type' => 'steps']],
        ], $schema, $pattern);

        $this->assertSame([
            'faqs' => [['id' => 'id1', 'q' => 'Why?']],
            'tags' => ['a', '2'],
            'meta' => ['seo' => 'Title'],
            'body' => [['id' => 'id3', 'type' => 'steps', 'enabled' => true, 'rows' => [['id' => 'id2', 'q' => 'One']]]],
        ], $built->data);
        // A reference in a group is the group's, and simply not written.
        $this->assertSame([], $built->notes);

        $built = (new EntryBuilder($options))->build(['body' => [['type' => 'nope']]], $schema);
        $this->assertSame(['A block of type "nope" does not exist in body and was left out.'], $built->notes);
    }

    public function test_rich_text_goes_through_the_dialect(): void
    {
        $schema = new Schema([
            new Field('body', Kind::RichText, type: 'bard', sets: ['pull_quote' => new Set('Pull quote', '', [new Field('text', Kind::LongText)])]),
            new Field('notes', Kind::RichText, type: 'markdown'),
            new Field('html', Kind::RichText, type: 'bard', meta: ['save_html' => true]),
        ]);

        $data = (new EntryBuilder(LayoutOptions::statamic(), new BardDialect(newId: fn () => 'q1')))->build([
            'body' => "Hello\n\n> *Quoted*",
            'notes' => " Kept **as** it is \n",
            'html' => 'A <b>tag</b>',
        ], $schema)->data;

        $this->assertSame([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['type' => 'set', 'attrs' => ['id' => 'q1', 'values' => ['type' => 'pull_quote', 'text' => '*Quoted*']]],
        ], $data['body']);
        $this->assertSame('Kept **as** it is', $data['notes']);
        $this->assertSame('<p>A &lt;b&gt;tag&lt;/b&gt;</p>', $data['html']);
    }

    public function test_a_link_to_choose_written_with_a_spaced_hint_is_stored_as_a_link(): void
    {
        $schema = new Schema([
            new Field('body', Kind::RichText, type: 'bard'),
            new Field('notes', Kind::RichText, type: 'markdown'),
        ]);

        $data = (new EntryBuilder(LayoutOptions::statamic(), new BardDialect))->build([
            'body' => 'See [our winter structure](#gw-link:Winter structure).',
            'notes' => 'See [our winter structure](#gw-link:Winter structure).',
        ], $schema)->data;

        $this->assertSame([['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'See '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#gw-link:Winter-structure', 'rel' => null, 'target' => null, 'title' => null]]], 'text' => 'our winter structure'],
            ['type' => 'text', 'text' => '.'],
        ]]], $data['body']);
        $this->assertSame('See [our winter structure](#gw-link:Winter-structure).', $data['notes']);
    }

    public function test_values_are_read_as_their_kind(): void
    {
        $schema = new Schema([
            new Field('on', Kind::Toggle),
            new Field('count', Kind::Number),
            new Field('bad', Kind::Number),
            new Field('picks', Kind::Choices, options: ['a' => 'Apple', 'true' => 'Yes']),
            new Field('any', Kind::Choice),
            new Field('text', Kind::Text),
            new Field('image', Kind::Reference),
        ]);

        $built = (new EntryBuilder)->build(['on' => 'yes', 'count' => '4.5', 'bad' => 'four', 'picks' => ['apple', true, 'pear'], 'any' => 12, 'text' => ['no'], 'image' => 'x.jpg'], $schema);

        $this->assertSame(['on' => true, 'count' => 4.5, 'picks' => ['a', 'true'], 'any' => '12'], $built->data);
        $this->assertSame(['"pear" is not an option for picks and was left out.'], $built->notes);
        $this->assertSame(['data' => $built->data, 'notes' => $built->notes], $built->toArray());
    }
}
