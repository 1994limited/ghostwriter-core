<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingFixer;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingPolicy;

/**
 * What every addon's SchemaReader and apply path must do with heading
 * levels (seo-layer-design.md §6.3): the reader records the levels a
 * rich-text field's editor offers, and markdown with `#` to `####`, once
 * the SEO pass has fitted it, goes through the real apply path into a
 * field that allows only H2 and H3 with two levels and every word.
 */
trait HeadingLevelsContract
{
    /** A rich-text field, as the addon's reader reads it, whose editor offers H2 and H3 only. */
    abstract protected function twoLevelField(): Field;

    /** A rich-text field, as the reader reads it, whose editor offers no headings. */
    abstract protected function noHeadingField(): Field;

    /**
     * Markdown through the addon's real apply path into the field, read
     * back as markdown with the addon's dialect.
     */
    abstract protected function stored(string $markdown, Field $field): string;

    public function test_the_reader_records_the_levels_the_editor_offers(): void
    {
        $this->assertSame([2, 3], HeadingLevels::allowed($this->twoLevelField()));
        $this->assertSame([], HeadingLevels::allowed($this->noHeadingField()));
    }

    public function test_four_levels_go_into_a_two_level_field_as_two_and_keep_every_word(): void
    {
        $markdown = "# Winter garden care\n\nWinter is when a garden is set up.\n\n## The visits\n\nFour a winter.\n\n### What we bring\n\nSecateurs.\n\n#### On wet days\n\nWe wait.";
        $field = $this->twoLevelField();
        $fitted = (new HeadingFixer)->fix($markdown, HeadingPolicy::for($field))->markdown;
        $stored = $this->stored($fitted, $field);

        preg_match_all('/^(#{1,6})\s/m', $stored, $levels);
        $this->assertSame(['##', '###'], array_values(array_unique($levels[1])));
        $this->assertSame(self::headingWords($markdown), self::headingWords($stored));
    }

    public function test_a_field_with_no_headings_gets_lead_ins(): void
    {
        $field = $this->noHeadingField();
        $stored = $this->stored((new HeadingFixer)->fix("## The visits\n\nFour a winter.", HeadingPolicy::for($field))->markdown, $field);

        $this->assertDoesNotMatchRegularExpression('/^#/m', $stored);
        $this->assertStringContainsString('The visits', $stored);
        $this->assertStringContainsString('Four a winter.', $stored);
    }

    /**
     * @return list<string>
     */
    private static function headingWords(string $markdown): array
    {
        return NormalisedText::words((string) preg_replace('/[*#_]+/', ' ', $markdown));
    }
}
