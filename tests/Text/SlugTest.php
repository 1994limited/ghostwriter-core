<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use PHPUnit\Framework\TestCase;

class SlugTest extends TestCase
{
    public function test_a_slug_is_lower_case_ascii_words_joined_by_hyphens(): void
    {
        $this->assertSame('brown-rocks-at-golden-hour', Slug::make('  Brown rocks, at golden hour! '));
        $this->assertSame('creme-brulee-a-paris', Slug::make('Crème brûlée à Paris'));
        $this->assertSame('strasse-aeroskobing-lodz', Slug::make('Straße Ærøskøbing Łódź'));
        $this->assertSame('fish-and-chips', Slug::make('Fish & Chips'));
        $this->assertSame('grandmas-kitchen', Slug::make("Grandma's kitchen"));
        $this->assertSame('', Slug::make('!!! 🙂 ...'));
    }

    public function test_other_scripts_become_ascii_or_nothing(): void
    {
        foreach (['東京の夜景', 'Москва зимой', 'نخلة'] as $text) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]*$/', Slug::make($text), $text);
        }
    }

    public function test_a_long_slug_is_cut_between_words(): void
    {
        $slug = Slug::make('a very long description of a bowl of soup sitting on an old wooden kitchen table by a window', 40);

        $this->assertLessThanOrEqual(40, strlen($slug));
        $this->assertSame('a-very-long-description-of-a-bowl-of', $slug);
        $this->assertSame(str_repeat('a', 10), Slug::make(str_repeat('a', 30), 10), 'One long word is cut where it must be.');
    }

    public function test_clip_makes_one_line_cut_at_a_word(): void
    {
        $this->assertSame('A bowl of soup', Slug::clip("  A   bowl\nof\tsoup  ", 50));
        $this->assertSame('A bowl of soup on', Slug::clip('A bowl of soup on, a table', 18));
        $this->assertSame('Ünïcödé wörds hère', Slug::clip('Ünïcödé wörds hère and more', 20));
        $this->assertSame(10, mb_strlen(Slug::clip(str_repeat('é', 30), 10)));
        $this->assertTrue(mb_check_encoding(Slug::clip("Bad\xC3 bytes", 20), 'UTF-8'));
    }

    public function test_upper_first_handles_multibyte_letters(): void
    {
        $this->assertSame('Éclair', Slug::upperFirst('éclair'));
        $this->assertSame('', Slug::upperFirst(''));
    }
}
