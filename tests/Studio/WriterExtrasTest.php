<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\SourceKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Extras\ExtrasReaderTest;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;

/** The writer prepares extras in the same call as the draft, for the block types the site has. */
final class WriterExtrasTest extends StudioTestCase
{
    public function test_the_writer_is_offered_the_extras_this_site_can_show_and_its_reply_is_checked(): void
    {
        $context = new WriterContext(new ContentKind('page', 'Page'), '', Layout::fromSchema(ExtrasReaderTest::schema()), '');
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter care, across Northumberland.']]);
        $this->fake->respond('writer', self::reply(<<<'TEXT'
            <reply>Here it is.</reply>
            <draft>
            title: Winter care
            intro: Four visits between November and February.
            </draft>
            <extras>
            - kind: stats
              items:
                - text: "4 visits a winter"
                  source: { from: draft, quote: "Four visits between November and February" }
                - text: "12 years of winter visits"
                  source: { from: draft, quote: "Four visits between November and February" }
            </extras>
            TEXT));

        $studio = $this->studio();
        $response = $studio->write($conversation, $context);
        $extras = $studio->extras($response, $conversation, $context);

        $instructions = $this->sent('writer')->instructions;
        $this->assertSame(1, substr_count($instructions, '## Extras you may prepare'));
        $this->assertStringContainsString("- `stats`: numbers worth pulling out; each item has `text` (\"4 visits a winter\"), and may split it into `value` (\"4\") and `label` (\"visits a winter\"). It would go in the “Stats” block.\n- `faq`: ", $instructions);
        $this->assertStringNotContainsString('`cta`', $instructions, 'a kind the site has no block for is not offered');
        $this->assertStringContainsString('{ from: answer, ref: 1, quote:', $instructions);
        $this->assertSame('Here it is.', $response->reply);
        $this->assertSame(['x1.1'], array_keys($extras->items()), 'the invented number is dropped');
        $this->assertSame(SourceKind::Draft, $extras->item('x1.1')?->source?->kind);
        $this->assertCount(1, $this->fake->requests(), 'extras come in the same call');
    }

    public function test_without_a_place_for_any_extra_the_writer_is_told_nothing_new(): void
    {
        $plain = new WriterContext(new ContentKind('page', 'Page'), '', new Layout("- `title` (text)\n"), '');

        $this->assertSame('', $this->studio()->extrasSection($plain));
        $this->assertStringEndsWith('Follow the fields and the guidance.', $this->studio()->writerInstructions($plain));
    }

    public function test_a_reply_without_its_reply_block_still_keeps_extras_out_of_it(): void
    {
        $response = TaggedResponse::parse("Done.\n<draft>\ntitle: A\n</draft>\n<extras>\n- kind: faq\n</extras>", 'draft');

        $this->assertSame(['Done.', '- kind: faq'], [$response->reply, $response->extras]);
        $this->assertNull(TaggedResponse::parse('<reply>Hi</reply>', 'draft')->extras);
    }
}
