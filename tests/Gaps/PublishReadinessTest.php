<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Readiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use PHPUnit\Framework\TestCase;

class PublishReadinessTest extends TestCase
{
    public function test_one_message_for_the_page_and_one_per_field(): void
    {
        $readiness = PublishReadiness::standard()->check($this->context());

        $this->assertTrue($readiness->blocked());
        $this->assertFalse($readiness->warns());
        $this->assertSame(['ask', 'ask', 'image-placeholder', 'link'], array_map(fn ($gap) => $gap->kind->value, $readiness->problems()));
        $this->assertSame(
            '4 things to finish before this page goes live: Intro (adult ticket price); Intro (child ticket price); Hero: Picture (the image placeholder); Hero: Button (a link to choose).',
            $readiness->message()->english(),
        );
        $this->assertSame([
            'intro' => 'Add adult ticket price before publishing. Add child ticket price before publishing.',
            'blocks.0.picture' => 'Replace the image placeholder before publishing.',
            'blocks.0.button' => 'Choose where this link goes before publishing.',
        ], $readiness->byField());
        $this->assertSame(['intro', 'blocks'], array_keys($readiness->byField(topLevel: true)));
        $this->assertSame('gaps.publish.field.ask:adult ticket price gaps.publish.field.ask:child ticket price', $readiness->byField(fn (Message $m) => $m->key.':'.($m->params['hint'] ?? ''))['intro'], 'The addon translates with its own system.');
    }

    public function test_required_fields_are_left_to_the_cms_and_not_counted(): void
    {
        $readiness = PublishReadiness::standard()->check($this->context(['intro' => '', 'blocks' => []]));

        $this->assertTrue($readiness->ready());
        $this->assertFalse($readiness->blocked());
        $this->assertSame('Ready to publish.', $readiness->message()->english());
        $this->assertSame([], $readiness->report()->all(), 'The CMS\'s own validation reports the empty required Intro on save.');
        $this->assertSame([], $readiness->byField());
    }

    public function test_an_image_the_page_needs_prompts_but_never_blocks(): void
    {
        $readiness = PublishReadiness::standard()->check($this->context([
            'intro' => 'A roof garden above a café, with beds of herbs for the kitchen and a few tables among them.',
            'blocks' => [['type' => 'hero', 'picture' => [], 'button' => '']],
        ], pictureRequired: true));

        $this->assertTrue($readiness->ready(), 'The CMS says so on save; Ghostwriter only prompts.');
        $this->assertSame(['image-empty|blocks/0/picture||0'], array_map(fn ($gap) => $gap->id, $readiness->report()->all()));
        $this->assertSame(1, $readiness->report()->count());
        $this->assertCount(1, $readiness->report()->prompting());
    }

    public function test_warn_mode_lets_it_through_with_one_warning(): void
    {
        $readiness = (new PublishReadiness(mode: OnPublish::Warn))->check($this->context(['intro' => 'Only [[ask: one price]].', 'blocks' => []]));

        $this->assertFalse($readiness->blocked());
        $this->assertTrue($readiness->warns());
        $this->assertSame('Published with 1 thing still to finish: Intro (one price).', $readiness->message()->english());
        $this->assertSame(['ready' => false, 'blocked' => false, 'mode' => 'warn'], array_slice($readiness->toArray(), 0, 3));
    }

    public function test_the_mode_comes_from_the_new_setting_then_the_stock_one_and_blocks_otherwise(): void
    {
        $this->assertSame(OnPublish::Warn, OnPublish::fromConfig('warn'));
        $this->assertSame(OnPublish::Warn, OnPublish::fromConfig(' WARN '));
        $this->assertSame(OnPublish::Block, OnPublish::fromConfig('block', 'warn'), 'The new setting wins.');
        $this->assertSame(OnPublish::Warn, OnPublish::fromConfig(null, 'warn'), 'The stock design\'s setting is read when the new one isn\'t set.');
        $this->assertSame(OnPublish::Warn, OnPublish::fromConfig('', 'warn'));
        $this->assertSame(OnPublish::Block, OnPublish::fromConfig(null));
        $this->assertSame(OnPublish::Block, OnPublish::fromConfig('sometimes'), 'Anything unknown blocks.');
        $this->assertSame('publish.on_unfinished', OnPublish::CONFIG);
        $this->assertSame('stock.on_publish', OnPublish::LEGACY_CONFIG);
    }

    public function test_an_empty_readiness_is_ready(): void
    {
        $readiness = new Readiness([], OnPublish::Block);

        $this->assertTrue($readiness->ready());
        $this->assertSame('gaps.publish.ready', $readiness->message()->key);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function context(array $values = [], bool $pictureRequired = false): GapContext
    {
        return new GapContext(
            schema: new Schema([
                new Field('intro', Kind::RichText, 'Intro', required: true),
                new Field('blocks', Kind::Blocks, 'Blocks', sets: ['hero' => new Set('Hero', '', [
                    new Field('picture', Kind::Reference, 'Picture', required: $pictureRequired, files: true, meta: ['images' => true]),
                    new Field('button', Kind::RichText, 'Button'),
                ])]),
            ]),
            entry: new EntryData($values + [
                'intro' => 'Adults [[ask: adult ticket price]], children [[ask: child ticket price]].',
                'blocks' => [['type' => 'hero', 'picture' => ['assets::ghostwriter/'.Placeholders::FILENAME], 'button' => '[Book](#gw-link:booking)']],
            ]),
            richText: new MarkdownAsStored,
            placeholders: new MemoryAssets,
            assets: new MemoryAssets,
        );
    }
}
