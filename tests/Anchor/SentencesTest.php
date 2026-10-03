<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use PHPUnit\Framework\TestCase;

final class SentencesTest extends TestCase
{
    public function test_sentences_split_at_stops_and_line_breaks(): void
    {
        $text = "We prune, e.g. the roses. Mr. Smith asks “why?” Then we feed!\nNo stop here";

        $this->assertSame(
            ['We prune, e.g. the roses.', 'Mr. Smith asks “why?”', 'Then we feed!', 'No stop here'],
            array_map(fn (array $span) => mb_substr($text, $span[0], $span[1]), Sentences::split($text)),
        );
    }

    public function test_the_sentences_a_range_covers(): void
    {
        $text = 'First one. The quoted words are here. Last one.';
        [$offset, $length] = Sentences::covering($text, mb_strpos($text, 'quoted'), 6);

        $this->assertSame('The quoted words are here.', mb_substr($text, $offset, $length));

        [$offset, $length] = Sentences::covering($text, mb_strpos($text, 'one.'), 20);
        $this->assertSame('First one. The quoted words are here.', mb_substr($text, $offset, $length));
    }

    public function test_a_range_in_one_block(): void
    {
        $markdown = "A paragraph here.\n\n| Day | Price |\n| --- | --- |\n| Mon | £12 |";

        $this->assertTrue(Sentences::inOneBlock($markdown, 2, 9));
        $this->assertFalse(Sentences::inOneBlock($markdown, 10, 12), 'across a line break');
        $this->assertTrue(Sentences::inOneBlock($markdown, mb_strpos($markdown, '£12'), 3));
        $this->assertFalse(Sentences::inOneBlock($markdown, mb_strpos($markdown, 'Mon'), 9), 'across a table cell');
    }
}
