<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Preview\BlockMap;
use NineteenNinetyFour\Ghostwriter\Core\Preview\MappedBlock;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\UnitsTest;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use PHPUnit\Framework\TestCase;

final class PreviewMarkersTest extends TestCase
{
    public function test_a_marker_is_tag_characters_and_round_trips(): void
    {
        $marker = PreviewMarkers::encode('b7.2');

        $this->assertSame("\u{E0067}\u{E0077}\u{E0062}\u{E0037}\u{E002E}\u{E0032}\u{E007F}", $marker);
        $this->assertSame([['payload' => 'b7.2', 'key' => 'b7', 'field' => 2, 'offset' => 3]], PreviewMarkers::decode('<p>'.$marker.'Hello</p>'));
        $this->assertSame('s12', PreviewMarkers::decode(PreviewMarkers::encode('s12'))[0]['key']);
        $this->assertNull(PreviewMarkers::decode(PreviewMarkers::encode('x 1'))[0]['key'], 'a payload not in Ghostwriter\'s shape');
        $this->assertSame('<p>Hello</p>', PreviewMarkers::stripText('<p>'.$marker.'Hello</p>'));
    }

    public function test_a_subdivision_flag_is_never_a_marker(): void
    {
        $flag = "\u{1F3F4}\u{E0067}\u{E0077}\u{E0062}\u{E0073}\u{E007F}";

        $this->assertSame([], PreviewMarkers::decode($flag));
        $this->assertSame($flag, PreviewMarkers::stripText($flag));
        $this->assertFalse(PreviewMarkers::contains(['a' => [$flag]]));
        $this->assertTrue(PreviewMarkers::contains(['a' => [PreviewMarkers::encode('f1').'x']]));
    }

    public function test_a_payload_is_short_printable_ascii(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PreviewMarkers::encode(str_repeat('b', 17));
    }

    public function test_plain_text_gets_its_marker_at_the_end_unless_it_is_an_address(): void
    {
        $m = PreviewMarkers::encode('f1');

        $this->assertSame("  Winter care{$m} \n", PreviewMarkers::markText("  Winter care \n", $m), 'before trailing whitespace');
        $this->assertSame("Book a visit.{$m}", PreviewMarkers::markText('Book a visit.', $m), 'after punctuation');
        $this->assertSame("Winter care 🌱{$m}", PreviewMarkers::markText('Winter care 🌱', $m), 'after an emoji');
        $this->assertSame("We 👨‍👩‍👧{$m}", PreviewMarkers::markText('We 👨‍👩‍👧', $m), 'after a ZWJ sequence, whole');

        foreach (['https://example.com', '/services', '#contact', 'mailto:a@b.c', 'tel:0123', ''] as $address) {
            $this->assertSame($address, PreviewMarkers::markText($address, $m));
        }
    }

    public function test_a_marker_never_follows_a_black_flag(): void
    {
        $m = PreviewMarkers::encode('f1');
        $marked = PreviewMarkers::markText("Ahoy \u{1F3F4}", $m);

        $this->assertSame("Ahoy {$m}\u{1F3F4}", $marked, 'before the flag, or it would read as a subdivision flag');
        $this->assertSame(['f1'], array_column(PreviewMarkers::decode($marked), 'payload'));

        $scotland = "Go \u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
        $marked = PreviewMarkers::markText($scotland, $m);

        $this->assertSame($scotland.$m, $marked, 'a whole subdivision flag ends with its cancel tag, so the marker can follow');
        $this->assertSame(['f1'], array_column(PreviewMarkers::decode($marked), 'payload'));
        $this->assertSame($scotland, PreviewMarkers::stripText($marked));
    }

    public function test_title_case_and_capitalise_filters_leave_the_text_and_its_trailing_marker_intact(): void
    {
        // Antlers `title` and Laravel's Str::title; Twig's `capitalize` (first letter up, the rest down); `ucfirst`.
        $filters = [
            'title' => fn (string $s) => mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'),
            'capitalize' => fn (string $s) => mb_strtoupper(mb_substr($s, 0, 1)).mb_strtolower(mb_substr($s, 1)),
            'ucfirst' => fn (string $s) => ucfirst($s),
            'upper' => fn (string $s) => mb_strtoupper($s),
        ];

        foreach (['winter care visits', 'émile’s garden, in spring.', 'book now 🌱'] as $text) {
            $marked = PreviewMarkers::markText($text, PreviewMarkers::encode('b12.3'));

            foreach ($filters as $name => $filter) {
                $page = $filter($marked);

                $this->assertSame(['b12.3'], array_column(PreviewMarkers::decode($page), 'payload'), "{$name}({$text}) lost its marker");
                $this->assertSame($filter($text), PreviewMarkers::stripText($page), "{$name}({$text}) changed the text");
            }
        }

        $this->assertSame('Winter Care Visits', PreviewMarkers::stripText(mb_convert_case(PreviewMarkers::markText('winter care visits', PreviewMarkers::encode('f1')), MB_CASE_TITLE, 'UTF-8')));
        $this->assertSame('Winter care', PreviewMarkers::stripText(ucfirst(PreviewMarkers::markText('winter care', PreviewMarkers::encode('f1')))), 'the first letter is the text\'s');
    }

    public function test_markdown_gets_markers_at_the_end_of_its_last_line_of_text(): void
    {
        $m = '§';
        $mark = fn (string $markdown, int $line = PreviewMarkers::LAST) => PreviewMarkers::markMarkdown($markdown, [$line => $m]);

        $this->assertSame('## Who it suits§', $mark('## Who it suits'));
        $this->assertSame('## Who it suits§ ##', $mark('## Who it suits ##'), 'before a heading\'s closing hashes');
        $this->assertSame("- Lawns\n- Gravel§\n\n", $mark("- Lawns\n- Gravel\n\n"), 'the last line with text');
        $this->assertSame("- Lawns§\n- Gravel", $mark("- Lawns\n- Gravel", 0));
        $this->assertSame('> Quoted.§', $mark('> Quoted.'));
        $this->assertSame('Cut **back**§', $mark('Cut **back**'), 'after the emphasis closes');
        $this->assertSame('See [our prices](/prices)§', $mark('See [our prices](/prices)'), 'after the link, not in it');
        $this->assertSame('Book now! 🌱§', $mark('Book now! 🌱'));
        $this->assertSame("| Day | Price |\n| --- | --- |\n| Mon | £40§ |", $mark("| Day | Price |\n| --- | --- |\n| Mon | £40 |"), 'before a row\'s last pipe');
        $this->assertSame("Line one§\\\nLine two", PreviewMarkers::markMarkdown("Line one\\\nLine two", [0 => $m]), 'before a hard break');
        $this->assertSame("Intro.§\n\n```\ncode\n```", $mark("Intro.\n\n```\ncode\n```"), 'a code block is skipped');
        $this->assertSame("Intro.§\n\n---\n\n[a]: /x", $mark("Intro.\n\n---\n\n[a]: /x"), 'so are rules and link definitions');
        $this->assertSame("One\r\n\r\nTwo§", $mark("One\r\n\r\nTwo"), 'line endings kept');
        $this->assertSame('One§¶', PreviewMarkers::markMarkdown('One', [0 => '§', PreviewMarkers::LAST => '¶']), 'two at one place keep their order');
    }

    public function test_html_gets_markers_after_its_last_text_without_changing_anything_else(): void
    {
        $html = "<figure class=\"x\"><img src=\"a.jpg\" alt=\"\"></figure>\n<!-- note -->\n<h2 id=\"a\"> <em>Who</em> it suits </h2><p>Gardens &amp; trees</p>\n<script>var a = 1;</script>";

        $this->assertSame(
            "<figure class=\"x\"><img src=\"a.jpg\" alt=\"\"></figure>\n<!-- note -->\n<h2 id=\"a\"> <em>Who</em> it suitsB </h2><p>Gardens &amp; treesA</p>\n<script>var a = 1;</script>",
            PreviewMarkers::markHtml($html, [PreviewMarkers::LAST => 'A', 1 => 'B']),
        );
        $this->assertSame('<p>OneAB</p>', PreviewMarkers::markHtml('<p>One</p>', [0 => 'A', PreviewMarkers::LAST => 'B']), 'two at one place keep their order');
        $this->assertSame('<p>See <a href="/p">our <strong>prices</strong></a>A</p>', PreviewMarkers::markHtml('<p>See <a href="/p">our <strong>prices</strong></a></p>', [PreviewMarkers::LAST => 'A']), 'outside a link and its emphasis, inside the paragraph');
        $this->assertSame('<ul><li>Lawns</li><li>Gravel.A</li></ul>', PreviewMarkers::markHtml('<ul><li>Lawns</li><li>Gravel.</li></ul>', [PreviewMarkers::LAST => 'A']), 'inside the last item');
        $this->assertSame('<p>Book now 🌱A</p><hr>', PreviewMarkers::markHtml('<p>Book now 🌱</p><hr>', [PreviewMarkers::LAST => 'A']));
        $this->assertSame('LeadA<h2>One</h2>', PreviewMarkers::markHtml('Lead<h2>One</h2>', [-1 => 'A']), 'text before the first element');
    }

    public function test_bard_gets_markers_at_the_end_of_the_last_text_node(): void
    {
        $nodes = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Bold'], ['type' => 'text', 'text' => ' then plain ']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Heading']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'See '], ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/p']]], 'text' => 'our prices']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Not here']]],
        ];
        $marked = PreviewMarkers::markBard($nodes, [0 => 'A', 1 => 'B', PreviewMarkers::LAST => 'C']);

        $this->assertSame(' then plainA ', $marked[0]['content'][1]['text']);
        $this->assertSame('HeadingB', $marked[1]['content'][0]['text']);
        $this->assertSame('our prices', $marked[2]['content'][1]['text'], 'not inside the link');
        $this->assertSame(['type' => 'text', 'text' => 'C'], $marked[2]['content'][2], 'in a text node of its own after it');
        $this->assertSame($nodes[3], $marked[3], 'a set is skipped');

        $real = PreviewMarkers::markBard($nodes, [PreviewMarkers::LAST => PreviewMarkers::encode('b1.0')]);
        $this->assertSame($nodes, PreviewMarkers::strip($real), 'the marker\'s own text node goes when it is stripped');
    }

    public function test_the_map_of_a_page(): void
    {
        $data = UnitsTest::draft();
        $data['page_builder'][1]['text'] = (new HtmlDialect)->fromMarkdown(UnitsTest::BODY, new Field('text', Kind::RichText));
        $preview = (new PreviewMarkers)->mark($data, UnitsTest::schema(), Units::fromDraft(UnitsTest::draft(), UnitsTest::schema()));
        $map = $preview->map;

        $this->assertSame(['f1', 'f2', 'b1', 'b2', 's1', 's2', 's3', 'b3', 'b4', 'f3'], $map->keys(), 'reading order');
        $this->assertSame(['u3', 'u4'], $map->get('b1')?->units);
        $this->assertSame(['garden.jpg'], $map->get('b1')?->assets);
        $this->assertSame([0 => 'heading', 1 => 'image'], $map->get('b1')?->fields);
        $this->assertSame('page_builder/0', $map->get('b1')?->path);
        $this->assertSame('Hero', $map->get('b1')?->label);
        $this->assertSame(MappedBlock::SECTION, $map->get('s2')?->kind);
        $this->assertSame('b2', $map->get('s2')?->parent);
        $this->assertSame(['u6'], $map->get('s2')?->units);
        $this->assertSame('What the visits are', $map->get('s2')?->label);
        $this->assertSame(['what the visits are'], $map->get('s2')?->anchors);
        $this->assertSame('s2', $map->forUnit('u6')?->key);
        $this->assertSame('b1', $map->forUnit('u4')?->key);
        $this->assertSame(1, $map->depth($map->get('s2') ?? $this->fail()));
        $this->assertEquals($map, BlockMap::fromArray(json_decode((string) json_encode($map->toArray()), true)));

        $this->assertSame(PreviewMarkers::strip($preview->data), $data, 'markers are all that was added');
        $this->assertSame(sha1((string) json_encode($data)), $preview->hash);
        $this->assertSame($preview->hash, (new PreviewMarkers)->mark($preview->data, UnitsTest::schema())->hash, 'the hash ignores markers');
        $this->assertSame(['s1', 's2', 's3', 'b2.0'], array_column(PreviewMarkers::decode($preview->data['page_builder'][1]['text']), 'payload'));
    }

    public function test_bard_sections_and_nested_blocks(): void
    {
        $bard = new BardDialect;
        $rich = new Field('text', Kind::RichText, type: 'bard');
        $schema = new Schema([new Field('builder', Kind::Blocks, sets: [
            'grid' => new Set('Card grid', '', [new Field('heading', Kind::Text), new Field('children', Kind::Blocks, sets: [
                'card' => new Set('Card', '', [new Field('heading', Kind::Text), new Field('body', Kind::RichText, type: 'bard')]),
            ], engine: Field::CHILDREN)]),
            'text' => new Set('Text', '', [$rich]),
        ])]);
        $data = ['builder' => [
            ['type' => 'grid', 'id' => 'g1', 'heading' => 'Visits', 'children' => [
                ['type' => 'card', 'heading' => 'November', 'body' => $bard->fromMarkdown('Cut back.', $rich)],
                ['type' => 'card', 'enabled' => false, 'heading' => 'Hidden'],
                ['type' => 'card', 'heading' => 'February', 'body' => $bard->fromMarkdown('Feed.', $rich)],
            ]],
            ['type' => 'text', 'text' => $bard->fromMarkdown("## One\n\nFirst.\n\n## Two\n\nSecond.", $rich)],
        ]];
        $preview = (new PreviewMarkers)->mark($data, $schema);
        $map = $preview->map;

        $this->assertSame(['b1', 'b2', 'b3', 'b4', 's1', 's2'], $map->keys());
        $this->assertSame(['b1', 'b1', null, 'b4'], [$map->get('b2')?->parent, $map->get('b3')?->parent, $map->get('b4')?->parent, $map->get('s1')?->parent]);
        $this->assertSame('builder/#g1/children/2', $map->get('b3')?->path);
        $this->assertSame('Hidden', $preview->data['builder'][0]['children'][1]['heading'], 'a disabled block is left alone');
        $this->assertSame([], PreviewMarkers::decode($preview->data['builder'][1]['text'][0]['content'][0]['text']), 'not at the start: a heading filter would lowercase the first word');
        $this->assertSame(['s1'], array_column(PreviewMarkers::decode($preview->data['builder'][1]['text'][1]['content'][0]['text']), 'payload'), 'the end of its first section');
        $this->assertSame(['s2', 'b4.0'], array_column(PreviewMarkers::decode($preview->data['builder'][1]['text'][3]['content'][0]['text']), 'payload'), 'the end of the last section, and of the field');
        $this->assertSame(['b2.1'], array_column(PreviewMarkers::decode($preview->data['builder'][0]['children'][0]['body'][0]['content'][0]['text']), 'payload'));
    }

    public function test_assets_by_the_adapters_own_names(): void
    {
        $schema = new Schema([new Field('builder', Kind::Blocks, sets: ['image' => new Set('Image', '', [new Field('image', Kind::Reference, files: true)])])]);
        $preview = (new PreviewMarkers(fn (mixed $id) => is_int($id) ? "photo-{$id}.jpg" : null))->mark(['builder' => [['type' => 'image', 'image' => [12, 13]]]], $schema);

        $this->assertSame(['photo-12.jpg', 'photo-13.jpg'], $preview->map->get('b1')?->assets);
    }

    public function test_a_draft_never_keeps_a_marker(): void
    {
        $draft = Draft::parse('title: '.PreviewMarkers::encode('f1')."Winter care\nintro: Four visits");

        $this->assertSame('Winter care', $draft->title());
        $this->assertFalse(PreviewMarkers::contains($draft->data));
        $this->assertFalse(PreviewMarkers::contains($draft->raw));
    }
}
