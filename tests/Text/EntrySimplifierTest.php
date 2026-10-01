<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use PHPUnit\Framework\TestCase;

class EntrySimplifierTest extends TestCase
{
    private const SCHEMA = [
        ['handle' => 'title', 'kind' => 'text'],
        ['handle' => 'image', 'kind' => 'reference'],
        ['handle' => 'intro', 'kind' => 'richtext'],
        ['handle' => 'notes', 'kind' => 'richtext', 'format' => 'markdown'],
        ['handle' => 'body', 'kind' => 'blocks', 'sets' => [
            'text' => ['fields' => [['handle' => 'copy', 'kind' => 'richtext']]],
        ]],
        ['handle' => 'faqs', 'kind' => 'rows', 'fields' => [['handle' => 'question', 'kind' => 'text']]],
        ['handle' => 'meta', 'kind' => 'group', 'fields' => [['handle' => 'seo_title', 'kind' => 'text'], ['handle' => 'og', 'kind' => 'reference']]],
        ['handle' => 'featured', 'kind' => 'toggle'],
        ['handle' => 'empty', 'kind' => 'text'],
    ];

    public function test_an_entry_is_reduced_to_what_the_writer_fills(): void
    {
        $simple = (new EntrySimplifier)->simplify([
            'title' => '  Hello  ',
            'image' => [4],
            'intro' => '<p>Some <strong>bold</strong> words.</p>',
            'notes' => "  Already **markdown** <b>kept</b>\n",
            'body' => [
                ['id' => 1, 'type' => 'text', 'copy' => '<p>One</p>'],
                ['id' => 2, 'type' => 'text', 'enabled' => false, 'copy' => '<p>Off</p>'],
                ['id' => 3, 'type' => 'gallery', 'images' => [1]],
            ],
            'faqs' => [['id' => 'a', 'question' => ' Why? '], 'junk'],
            'meta' => ['seo_title' => 'SEO', 'og' => [9]],
            'featured' => true,
            'empty' => '',
            'unknown' => 'ignored',
        ], self::SCHEMA);

        $this->assertSame([
            'title' => 'Hello',
            'intro' => 'Some **bold** words.',
            'notes' => 'Already **markdown** <b>kept</b>',
            'body' => [['type' => 'text', 'copy' => 'One']],
            'faqs' => [['question' => 'Why?']],
            'meta' => ['seo_title' => 'SEO'],
            'featured' => true,
        ], $simple);
    }

    public function test_rich_text_stored_another_way_is_converted_by_the_caller(): void
    {
        $simplifier = new EntrySimplifier(richText: fn (mixed $value, array $spec) => is_array($value) ? implode(' ', $value) : trim((string) $value));

        $this->assertSame(['intro' => 'node tree'], $simplifier->simplify(['intro' => ['node', 'tree']], self::SCHEMA));
    }
}
