<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Craft and Statamic addons' TaggedResponseTest.
 */
class TaggedResponseTest extends TestCase
{
    public function test_it_separates_the_reply_from_the_document(): void
    {
        $response = TaggedResponse::parse("<reply>\nHere you go.\n</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n</draft>", 'draft', 12, 34);

        $this->assertSame('Here you go.', $response->reply);
        $this->assertSame("---\ntitle: A\n---\n\n## One", $response->document);
        $this->assertSame([12, 34], [$response->inputTokens, $response->outputTokens]);
    }

    public function test_a_reply_without_a_document_leaves_the_document_null(): void
    {
        $response = TaggedResponse::parse('<reply>1. What did it cost?</reply>', 'draft');

        $this->assertSame('1. What did it cost?', $response->reply);
        $this->assertNull($response->document);
    }

    public function test_a_document_cut_off_before_its_closing_tag_is_still_kept(): void
    {
        $response = TaggedResponse::parse("<reply>Draft below.</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n\nIt stops he", 'draft');

        $this->assertStringEndsWith('It stops he', (string) $response->document);
    }

    public function test_text_outside_the_format_becomes_the_reply(): void
    {
        $response = TaggedResponse::parse('I could not follow the format, sorry.', 'draft');

        $this->assertSame('I could not follow the format, sorry.', $response->reply);
        $this->assertNull($response->document);
    }

    public function test_the_images_block_is_kept_apart_from_the_reply(): void
    {
        $response = TaggedResponse::parse("Here it is.\n<draft>title: A</draft>\n<images>\ncover | find | harbour\n</images>", 'draft');

        $this->assertSame('Here it is.', $response->reply);
        $this->assertSame('cover | find | harbour', $response->images);
    }

    public function test_text_in_any_script_and_a_bad_byte_survive(): void
    {
        $this->assertSame('Voilà – 東京', TaggedResponse::parse('<reply>Voilà – 東京</reply>', 'draft')->reply);
        $this->assertSame("caf\xE9", TaggedResponse::parse("<reply>caf\xE9</reply>", 'draft')->reply);
    }
}
