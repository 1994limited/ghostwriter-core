<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
use PHPUnit\Framework\TestCase;

class DraftPreviewTest extends TestCase
{
    public function test_a_draft_is_laid_out_for_reading_with_paths_for_editing(): void
    {
        $schema = [
            ['handle' => 'title', 'display' => 'Title', 'kind' => 'text'],
            ['handle' => 'intro', 'display' => '', 'kind' => 'richtext'],
            ['handle' => 'body', 'display' => 'Body', 'kind' => 'blocks', 'sets' => [
                'text' => ['display' => 'Text', 'fields' => [['handle' => 'copy', 'display' => 'Copy', 'kind' => 'longtext']]],
            ]],
            ['handle' => 'meta', 'display' => 'Meta', 'kind' => 'group', 'fields' => [['handle' => 'seo_title', 'display' => 'SEO title', 'kind' => 'text']]],
            ['handle' => 'tags', 'display' => 'Tags', 'kind' => 'list'],
            ['handle' => 'featured', 'display' => 'Featured', 'kind' => 'toggle'],
            ['handle' => 'missing', 'display' => 'Missing', 'kind' => 'text'],
        ];

        $nodes = (new DraftPreview)->render([
            'title' => 'Hello',
            'intro' => 'Some **bold** and <script>alert(1)</script> [a](javascript:alert(1))',
            'body' => [['type' => 'text', 'copy' => 'Words'], ['type' => 'mystery']],
            'meta' => ['seo_title' => 'SEO'],
            'tags' => ['one', ['nested'], 'two'],
            'featured' => 'false',
        ], $schema);

        $this->assertSame(['title', 'intro', 'body', 'meta', 'tags', 'featured'], array_column($nodes, 'handle'));
        $this->assertSame(['kind' => 'text', 'text' => 'Hello'], array_intersect_key($nodes[0], ['kind' => 1, 'text' => 1]));
        $this->assertTrue($nodes[0]['editable']);

        $this->assertSame('intro', $nodes[1]['label']);
        $this->assertStringContainsString('<strong>bold</strong>', $nodes[1]['html']);
        $this->assertStringContainsString('&lt;script&gt;', $nodes[1]['html']);
        $this->assertStringNotContainsString('javascript:', $nodes[1]['html']);

        $this->assertSame(['body', 0, 'copy'], $nodes[2]['items'][0]['fields'][0]['path']);
        $this->assertFalse($nodes[2]['items'][1]['known']);
        $this->assertSame(['meta', 'seo_title'], $nodes[3]['fields'][0]['path']);
        $this->assertSame(['one', 'two'], $nodes[4]['items']);
        $this->assertSame('No', $nodes[5]['text']);
        $this->assertFalse($nodes[5]['editable']);
    }
}
