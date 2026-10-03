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

    public function test_plain_text_is_prefixed_unless_it_is_an_address(): void
    {
        $m = PreviewMarkers::encode('f1');

        $this->assertSame("  {$m}Winter care", PreviewMarkers::markText('  Winter care', $m));

        foreach (['https://example.com', '/services', '#contact', 'mailto:a@b.c', 'tel:0123', ''] as $address) {
            $this->assertSame($address, PreviewMarkers::markText($address, $m));
        }
    }

    public function test_markdown_gets_markers_after_a_lines_syntax(): void
    {
        $m = '§';
        $mark = fn (string $markdown, int $line = 0) => PreviewMarkers::markMarkdown($markdown, [$line => $m]);

        $this->assertSame('## §Who it suits', $mark('## Who it suits'));
        $this->assertSame("- §Lawns\n- Gravel", $mark("- Lawns\n- Gravel"));
        $this->assertSame('1. §First', $mark('1. First'));
        $this->assertSame('> §Quoted', $mark('> Quoted'));
        $this->assertSame('**§November:** Cut back', $mark('**November:** Cut back'));
        $this->assertSame('_§Gently_ does it', $mark('_Gently_ does it'));
        $this->assertSame('| §Day | Price |', $mark('| Day | Price |'));
        $this->assertSame("```\ncode\n```", $mark("```\ncode\n```"), 'a code fence is left alone');
        $this->assertSame("One\r\n\r\n§Two", $mark("One\r\n\r\nTwo", 2), 'line endings kept');
    }

    public function test_html_gets_markers_in_its_first_text_without_changing_anything_else(): void
    {
        $html = "<figure class=\"x\"><img src=\"a.jpg\" alt=\"\"></figure>\n<!-- note -->\n<h2 id=\"a\"> <em>Who</em> it suits</h2><p>Gardens &amp; trees</p>";

        $this->assertSame(
            "<figure class=\"x\"><img src=\"a.jpg\" alt=\"\"></figure>\n<!-- note -->\n<h2 id=\"a\"> <em>AWho</em> it suits</h2><p>BGardens &amp; trees</p>",
            PreviewMarkers::markHtml($html, [-1 => 'A', 2 => 'B']),
        );
        $this->assertSame('<p>ABOne</p>', PreviewMarkers::markHtml('<p>One</p>', [-1 => 'A', 0 => 'B']), 'two at one place keep their order');
        $this->assertSame('<script>var a = 1;</script><p>AOne</p>', PreviewMarkers::markHtml('<script>var a = 1;</script><p>One</p>', [-1 => 'A']));
    }

    public function test_bard_gets_markers_in_the_first_text_node(): void
    {
        $nodes = [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Not here']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Bold'], ['type' => 'text', 'text' => ' then plain']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Heading']]],
        ];
        $marked = PreviewMarkers::markBard($nodes, [-1 => 'A', 2 => 'B']);

        $this->assertSame('ABold', $marked[1]['content'][0]['text']);
        $this->assertSame('BHeading', $marked[2]['content'][0]['text']);
        $this->assertSame($nodes[0], $marked[0]);
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
        $this->assertSame(['b2.0', 's1', 's2', 's3'], array_column(PreviewMarkers::decode($preview->data['page_builder'][1]['text']), 'payload'));
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
        $this->assertSame(['b4.0', 's1'], array_column(PreviewMarkers::decode($preview->data['builder'][1]['text'][0]['content'][0]['text']), 'payload'));
        $this->assertSame(['s2'], array_column(PreviewMarkers::decode($preview->data['builder'][1]['text'][2]['content'][0]['text']), 'payload'));
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
