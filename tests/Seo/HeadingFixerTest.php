<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingChange;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingFixer;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SEO layer's heading rules (seo-layer-design.md §6.1), one fixture
 * per case: every fixture is also fixed twice (the same as once) and
 * keeps every word.
 */
final class HeadingFixerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, HeadingPolicy, string}>
     */
    public static function cases(): iterable
    {
        $all = new HeadingPolicy(2);
        $twoThree = new HeadingPolicy(2, [2, 3]);

        yield 'a body h1 under the template h1 starts at h2' => [
            "# Winter care\n\nIntro.\n\n## The visits\n\nText.",
            $all,
            "## Winter care\n\nIntro.\n\n### The visits\n\nText.",
        ];

        yield 'two h1s become h2s' => [
            "# One\n\nA.\n\n# Two\n\nB.",
            $all,
            "## One\n\nA.\n\n## Two\n\nB.",
        ];

        yield 'levels running through the value are re-ranked from the top' => [
            "### A\n\nText.\n\n#### B\n\nText.\n\n### C\n\nText.",
            $all,
            "## A\n\nText.\n\n### B\n\nText.\n\n## C\n\nText.",
        ];

        yield 'a local skip is closed' => [
            "## A\n\nText.\n\n#### B\n\nText.\n\n### C\n\nText.",
            $all,
            "## A\n\nText.\n\n### B\n\nText.\n\n### C\n\nText.",
        ];

        yield 'the first heading starts at the top, deeper ones follow it down' => [
            "### Deep first\n\nText.\n\n## Then shallow\n\nText.",
            $all,
            "## Deep first\n\nText.\n\n## Then shallow\n\nText.",
        ];

        yield 'a rank with no allowed level left becomes a lead-in of the paragraph after it' => [
            "## A\n\n### B\n\n#### C\n\nUnder C.\n\nMore under C.",
            $twoThree,
            "## A\n\n### B\n\n**C.** Under C.\n\nMore under C.",
        ];

        yield 'with no paragraph after it, a bold line ending in a full stop' => [
            "## A\n\n### B\n\n#### Tools you need:\n\n- Spade\n- Rake",
            $twoThree,
            "## A\n\n### B\n\n**Tools you need.**\n\n- Spade\n- Rake",
        ];

        yield 'a heading already ending in punctuation keeps it as a lead-in' => [
            "## A\n\n### B\n\n#### Why now?\n\nBecause.",
            $twoThree,
            "## A\n\n### B\n\n**Why now?** Because.",
        ];

        yield 'no headings allowed: every heading is a lead-in, and bold lines stay' => [
            "## The visits\n\nFour a winter.\n\n**Who it suits**\n\nMixed borders.",
            new HeadingPolicy(2, []),
            "**The visits.** Four a winter.\n\n**Who it suits**\n\nMixed borders.",
        ];

        yield 'a field made for the h1 (a hero heading with only H1) keeps it' => [
            "## Winches, rigged right\n\nThe rest.",
            new HeadingPolicy(2, [1]),
            "# Winches, rigged right\n\nThe rest.",
        ];

        yield 'a heading field in rich text (one heading, nothing else) is the template\'s' => [
            '# Winches, rigged right',
            $all,
            '# Winches, rigged right',
        ];

        yield 'a body that owns the h1 keeps it' => [
            "# Winter care\n\nIntro.\n\n## Visits\n\nText.",
            new HeadingPolicy(1),
            "# Winter care\n\nIntro.\n\n## Visits\n\nText.",
        ];

        yield 'an empty heading is removed' => [
            "## A\n\nText.\n\n## \n\nMore.\n\n### — \n\nEnd.",
            $all,
            "## A\n\nText.\n\nMore.\n\nEnd.",
        ];

        yield 'a heading holding only an ask is not empty' => [
            "## A\n\nText.\n\n## [[ask: name of the second section]]\n\nMore.",
            $all,
            "## A\n\nText.\n\n## [[ask: name of the second section]]\n\nMore.",
        ];

        yield 'a heading with a count to check keeps the marker and moves' => [
            "# [[check: 3 areas | from: Corbridge, Hexham, Morpeth]] we cover\n\nText.",
            $all,
            "## [[check: 3 areas | from: Corbridge, Hexham, Morpeth]] we cover\n\nText.",
        ];

        yield 'a bold line posing as a heading becomes one, at the depth of the heading before it' => [
            "## The visits\n\nIntro.\n\n**What we prune**\n\nShrubs.\n\n**What we mulch:**\n\n- Beds",
            $twoThree,
            "## The visits\n\nIntro.\n\n## What we prune\n\nShrubs.\n\n## What we mulch\n\n- Beds",
        ];

        yield 'a bold line with no heading before it starts at the top' => [
            "**Why winter**\n\nBecause.",
            $all,
            "## Why winter\n\nBecause.",
        ];

        yield 'bold lines that are not headings stay: punctuation, too long, before a heading, last' => [
            "**Book before November.**\n\nText.\n\n**This bold line has far too many words in it to be anyone's idea of a heading at all**\n\nText.\n\n**Before a heading**\n\n## Next\n\nText.\n\n**At the end**",
            $all,
            "**Book before November.**\n\nText.\n\n**This bold line has far too many words in it to be anyone's idea of a heading at all**\n\nText.\n\n**Before a heading**\n\n## Next\n\nText.\n\n**At the end**",
        ];

        yield 'a bold lead-in is never touched' => [
            "**November: Cut back.** Prune the shrubs.\n\n**January: Feed.** Mulch.",
            $all,
            "**November: Cut back.** Prune the shrubs.\n\n**January: Feed.** Mulch.",
        ];

        yield 'two bold runs on one line are not a heading' => [
            "**Prune** and **mulch**\n\nText.",
            $all,
            "**Prune** and **mulch**\n\nText.",
        ];

        yield 'headings in a code block are left alone' => [
            "## A\n\n```\n# not a heading\n```\n\n#### B\n\nText.",
            $all,
            "## A\n\n```\n# not a heading\n```\n\n### B\n\nText.",
        ];

        yield 'a tight list and everything around a heading stay exactly as written' => [
            "Intro line one\nline two.\n\n- one\n- two\n\n#### Then\n\n> A quote\n\n| a | b |\n|---|---|",
            $all,
            "Intro line one\nline two.\n\n- one\n- two\n\n## Then\n\n> A quote\n\n| a | b |\n|---|---|",
        ];

        yield 'nothing to fix is returned as it is' => [
            "Just a paragraph.\n\n- and a list",
            $all,
            "Just a paragraph.\n\n- and a list",
        ];
    }

    #[DataProvider('cases')]
    public function test_the_heading_rules(string $markdown, HeadingPolicy $policy, string $expected): void
    {
        $fixer = new HeadingFixer;
        $fixed = $fixer->fix($markdown, $policy);

        $this->assertSame($expected, $fixed->markdown);
        $this->assertSame($markdown !== $expected, $fixed->changed());

        $again = $fixer->fix($fixed->markdown, $policy);
        $this->assertFalse($again->changed(), 'Fixing twice is fixing once.');
        $this->assertSame($fixed->markdown, $again->markdown);

        $this->assertSame(self::words($markdown), self::words($fixed->markdown), 'No word changes.');
    }

    public function test_it_says_what_it_changed(): void
    {
        $fixed = (new HeadingFixer)->fix("# Title\n\n## \n\n**Bold one**\n\nText.\n\n#### Deep\n\nUnder.", new HeadingPolicy(2, [2, 3]));

        $this->assertSame([
            ['kind' => HeadingChange::REMOVED, 'from' => 2, 'to' => null, 'text' => ''],
            ['kind' => HeadingChange::FROM_BOLD, 'from' => null, 'to' => 1, 'text' => 'Bold one'],
            ['kind' => HeadingChange::LEVEL, 'from' => 1, 'to' => 2, 'text' => 'Title'],
            ['kind' => HeadingChange::LEVEL, 'from' => 1, 'to' => 2, 'text' => 'Bold one'],
            ['kind' => HeadingChange::LEVEL, 'from' => 4, 'to' => 3, 'text' => 'Deep'],
        ], array_map(fn (HeadingChange $change) => $change->toArray(), $fixed->changes));
        $this->assertSame("## Title\n\n## Bold one\n\nText.\n\n### Deep\n\nUnder.", $fixed->markdown);
    }

    public function test_the_policy_reads_its_levels_from_the_top_down(): void
    {
        $this->assertSame([2, 3, 4, 5, 6], (new HeadingPolicy(2))->levels());
        $this->assertSame('`##` to `######`', (new HeadingPolicy(2))->markdown());
        $this->assertSame('`##` and `###`', (new HeadingPolicy(2, [1, 2, 3]))->markdown());
        $this->assertSame('`###` only', (new HeadingPolicy(3, [2, 3]))->markdown());
        $this->assertSame('`##`, `####` and `#####`', (new HeadingPolicy(2, [2, 4, 5]))->markdown());
        $this->assertSame('h2–h6', (new HeadingPolicy(2))->constructs());
        $this->assertSame('h2, h3', (new HeadingPolicy(2, [2, 3]))->constructs());
        $this->assertFalse((new HeadingPolicy(2, []))->allowsHeadings());
        $this->assertSame('', (new HeadingPolicy(2, []))->markdown());
    }

    /**
     * @return list<string>
     */
    private static function words(string $markdown): array
    {
        return NormalisedText::words((string) preg_replace('/[*#_`>|-]+/', ' ', $markdown));
    }
}
