<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Craft and Filament addons' editing tests, plus the
 * Statamic behaviour (rows and groups) behind its options.
 */
class EntryMergerTest extends TestCase
{
    private const SCHEMA = [
        ['handle' => 'title', 'kind' => 'text'],
        ['handle' => 'image', 'kind' => 'reference'],
        ['handle' => 'body', 'kind' => 'blocks', 'sets' => [
            'text' => ['fields' => [['handle' => 'copy', 'kind' => 'richtext'], ['handle' => 'width', 'kind' => 'choice']]],
            'quote' => ['fields' => [['handle' => 'copy', 'kind' => 'text']]],
        ]],
        ['handle' => 'faqs', 'kind' => 'rows', 'fields' => [['handle' => 'question', 'kind' => 'text'], ['handle' => 'answer', 'kind' => 'longtext']]],
        ['handle' => 'meta', 'kind' => 'group', 'fields' => [['handle' => 'seo_title', 'kind' => 'text']]],
    ];

    public function test_blocks_are_matched_by_type_in_order(): void
    {
        $original = ['title' => 'Old', 'image' => [7], 'body' => [
            ['id' => 11, 'type' => 'text', 'enabled' => true, 'copy' => 'First', 'width' => 'wide'],
            ['id' => 12, 'type' => 'quote', 'enabled' => true, 'copy' => 'Said'],
            ['id' => 13, 'type' => 'text', 'enabled' => true, 'copy' => 'Second', 'width' => 'narrow'],
            ['id' => 14, 'type' => 'quote', 'enabled' => false, 'copy' => 'Hidden'],
        ]];

        // The writer moved the quote to the end and added a third text block.
        $built = ['body' => [
            ['type' => 'text', 'enabled' => true, 'copy' => 'First, better'],
            ['type' => 'text', 'enabled' => true, 'copy' => 'Second, better'],
            ['type' => 'quote', 'enabled' => true, 'copy' => 'Said, better'],
            ['type' => 'text', 'enabled' => true, 'copy' => 'New'],
        ]];

        $merged = (new EntryMerger)->merge($built, $original, self::SCHEMA);

        $this->assertSame([7], $merged['image']);
        $this->assertArrayNotHasKey('title', $merged, 'The title is never carried over.');
        $this->assertSame([11, 13, 12, null, 14], array_map(fn (array $block) => $block['id'] ?? null, $merged['body']));
        $this->assertSame(['wide', 'narrow'], [$merged['body'][0]['width'], $merged['body'][1]['width']]);
        $this->assertSame('Second, better', $merged['body'][1]['copy']);
        $this->assertFalse($merged['body'][4]['enabled']);
    }

    public function test_blocks_without_ids_keep_their_settings(): void
    {
        $merged = (new EntryMerger)->merge(['body' => [
            ['type' => 'text', 'copy' => 'First, better'],
            ['type' => 'quote', 'copy' => 'Said, better'],
            ['type' => 'text', 'copy' => 'Second, better'],
            'not a block',
        ]], ['body' => [
            ['type' => 'text', 'copy' => 'First', 'width' => 'wide'],
            ['type' => 'text', 'copy' => 'Second', 'width' => 'narrow'],
            ['type' => 'quote', 'copy' => 'Said'],
        ]], self::SCHEMA);

        $this->assertCount(3, $merged['body']);
        $this->assertSame(['wide', 'narrow'], [$merged['body'][0]['width'], $merged['body'][2]['width']]);
        $this->assertSame('Second, better', $merged['body'][2]['copy']);
    }

    public function test_by_default_rows_are_replaced_and_missing_fields_carried_over(): void
    {
        $original = ['faqs' => [['id' => 'r1', 'question' => 'Old?', 'answer' => 'Old.', 'icon' => 'q']], 'meta' => ['seo_title' => 'Old', 'noindex' => true], 'image' => [7]];
        $built = ['faqs' => [['question' => 'New?', 'answer' => 'New.']], 'meta' => ['seo_title' => 'New']];

        $merged = (new EntryMerger)->merge($built, $original, self::SCHEMA);

        $this->assertSame([['question' => 'New?', 'answer' => 'New.']], $merged['faqs']);
        $this->assertSame(['seo_title' => 'New', 'noindex' => true], $merged['meta']);
        $this->assertSame([7], $merged['image']);
    }

    public function test_rows_can_be_merged_by_position_and_missing_fields_left_out(): void
    {
        $original = ['faqs' => [['id' => 'r1', 'question' => 'Old?', 'answer' => 'Old.', 'icon' => 'q']], 'image' => [7]];
        $built = ['faqs' => [['question' => 'New?', 'answer' => 'New.'], ['question' => 'Extra?']]];

        $merged = (new EntryMerger(keepMissing: false, mergeRows: true))->merge($built, $original, self::SCHEMA);

        $this->assertSame([
            ['id' => 'r1', 'question' => 'New?', 'answer' => 'New.', 'icon' => 'q'],
            ['question' => 'Extra?'],
        ], $merged['faqs']);
        $this->assertArrayNotHasKey('image', $merged);
    }
}
