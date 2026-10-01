<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * Rich text HTML, read back as the markdown a writer types. Ported from the
 * Craft addon's HtmlToMarkdownTest.
 */
class HtmlToMarkdownTest extends TestCase
{
    public function test_rich_text_becomes_markdown(): void
    {
        $html = '<h2>Heading</h2><p>A <strong>bold</strong> and <em>italic</em> <a href="https://example.com">link</a>.</p>'
            .'<ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul><ol><li>first</li><li>second</li></ol>'
            .'<blockquote><p>Quoted.</p></blockquote><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>';

        $this->assertSame(
            "## Heading\n\nA **bold** and *italic* [link](https://example.com).\n\n- one\n- two\n  - nested\n\n1. first\n2. second\n\n> Quoted.\n\n| A | B |\n| --- | --- |\n| 1 | 2 |",
            (new HtmlToMarkdown)->convert($html),
        );
    }

    public function test_furniture_is_dropped_and_formatting_whitespace_ignored(): void
    {
        $html = "<p>\n    Before   the\n    picture.<br>New line.\n</p>\n<figure class=\"image\"><img src=\"/a.jpg\" alt=\"\"><figcaption>Caption</figcaption></figure>\n<craft-entry data-entry-id=\"4\"></craft-entry><p>After.</p>";

        $this->assertSame("Before the picture.  \nNew line.\n\nAfter.", (new HtmlToMarkdown)->convert($html));
    }

    public function test_text_outside_any_paragraph_is_kept(): void
    {
        $this->assertSame('Loose words, **some bold**.', (new HtmlToMarkdown)->convert('Loose words, <b>some bold</b>.'));
        $this->assertSame('', (new HtmlToMarkdown)->convert('  '));
    }

    public function test_the_embedded_elements_to_drop_can_be_chosen(): void
    {
        $html = '<p>Keep <x-widget>this widget</x-widget> out.</p><x-widget><p>Block widget</p></x-widget><craft-entry>Entry</craft-entry>';

        $this->assertSame("Keep this widget out.\n\nBlock widget", (new HtmlToMarkdown)->convert($html));
        $this->assertSame("Keep out.\n\nEntry", (new HtmlToMarkdown(['x-widget']))->convert($html));
    }
}
