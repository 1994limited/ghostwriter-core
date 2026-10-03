<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use PHPUnit\Framework\TestCase;

/** Quotes in the four field shapes: plain text, Markdown, HTML and Bard JSON, the last two read as markdown by their dialects. */
final class QuoteFinderTest extends TestCase
{
    private const MARKDOWN = "## Who it suits\n\nGardens with **mixed borders**, young trees and a lawn that matters to you. If your garden is mostly lawn and gravel, you probably don’t need it, and we’ll say so.\n\n- November: cut back\n- February: [feed and mulch](entry::feeding)";

    public function test_plain_text(): void
    {
        $text = 'Four visits between November and February, and we’ll say so.';
        $match = (new QuoteFinder)->find(new TextQuote("and we'll say so"), $text);

        $this->assertNotNull($match);
        $this->assertSame('and we’ll say so', $match->text($text));
    }

    public function test_markdown(): void
    {
        $match = (new QuoteFinder)->find(new TextQuote('Gardens with mixed borders, young trees'), self::MARKDOWN, markdown: true);

        $this->assertNotNull($match);
        $this->assertSame('Gardens with **mixed borders**, young trees', $match->text(self::MARKDOWN));
        $this->assertFalse($match->fuzzy);
    }

    public function test_html_read_as_markdown(): void
    {
        $this->assertFoundThrough(new HtmlDialect, new Field('body', Kind::RichText), (new HtmlDialect)->fromMarkdown(self::MARKDOWN, new Field('body', Kind::RichText)));
    }

    public function test_bard_read_as_markdown(): void
    {
        $field = new Field('body', Kind::RichText, type: 'bard');
        $bard = new BardDialect;
        $value = $bard->fromMarkdown(self::MARKDOWN, $field);

        $this->assertIsArray($value);
        $this->assertFoundThrough($bard, $field, $value);
    }

    public function test_without_markdown_the_syntax_is_text(): void
    {
        $this->assertNull((new QuoteFinder)->find(new TextQuote('Gardens with mixed borders, young'), self::MARKDOWN));
    }

    public function test_a_fuzzy_match_is_requoted_to_the_real_text(): void
    {
        $text = 'If your garden is mostly lawn and gravel, you probably don’t need it.';
        $match = (new QuoteFinder)->find(new TextQuote('your garden is mostly lawns and gravel'), $text);

        $this->assertNotNull($match);
        $this->assertTrue($match->fuzzy);
        $this->assertSame('your garden is mostly lawn and gravel', $match->requote($text)->exact);
        $this->assertSame('If ', $match->requote($text)->prefix);
    }

    public function test_a_repeat_is_picked_by_context_over_occurrence(): void
    {
        $text = 'Prune in March. Feed in March. Mulch in March.';
        $match = (new QuoteFinder)->find(new TextQuote('in March', 'Feed '), $text, 0);

        $this->assertNotNull($match);
        $this->assertSame(1, $match->occurrence);
        $this->assertSame(21, $match->offset);
    }

    public function test_a_quote_around_a_range(): void
    {
        $text = str_repeat('a', 40).'THE WORDS'.str_repeat('b', 40);
        $quote = TextQuote::around($text, 40, 9);

        $this->assertSame('THE WORDS', $quote->exact);
        $this->assertSame(str_repeat('a', 32), $quote->prefix);
        $this->assertSame(str_repeat('b', 32), $quote->suffix);
        $this->assertEquals($quote, TextQuote::fromArray($quote->toArray()));
        $this->assertSame(['exact' => 'x'], (new TextQuote('x'))->toArray());
    }

    public function test_a_quote_needs_words_and_a_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TextQuote(' ');
    }

    public function test_normalised_words(): void
    {
        $this->assertSame(['don', 't', 'need', 'it', '4', 'visits'], NormalisedText::words('**Don’t** need <em>it</em>: 4 visits'));
        $this->assertSame(['see', 'the', 'garden'], NormalisedText::words('See [the garden](entry::abc)'));
    }

    private function assertFoundThrough(RichTextDialect $dialect, Field $field, mixed $stored): void
    {
        $markdown = (string) $dialect->toMarkdown($stored, $field);
        $finder = new QuoteFinder;

        foreach (['mixed borders, young trees', "you probably don't need it, and we'll say so", 'February: feed and mulch', 'Who it suits'] as $exact) {
            $match = $finder->find(new TextQuote($exact), $markdown, markdown: true);

            $this->assertNotNull($match, "\"{$exact}\" not found in:\n{$markdown}");
            $this->assertSame(NormalisedText::string($exact), NormalisedText::string($match->text($markdown), true));
        }
    }
}
