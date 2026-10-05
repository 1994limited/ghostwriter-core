<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaRange;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoMetaCheck;
use PHPUnit\Framework\TestCase;

/**
 * The checks on a search title and description (SEO layer §9.2, §5.3):
 * lengths, the site name's budget, nothing the page doesn't say, no figure
 * still to confirm, plain text.
 */
final class SeoMetaCheckTest extends TestCase
{
    private const PAGE = <<<'MD'
        Winter care visits

        We visit established gardens once a month from November to February, across Northumberland, Durham and the Tyne Valley. We cut back, divide and mulch the borders, and leave seed heads standing for the birds.

        We look after [[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]] and a visit costs [[ask: price of a visit]].
        MD;

    private const GOOD = 'Monthly winter visits to cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, November to February.';

    private static function description(): MetaRange
    {
        return MetaRange::for(SeoField::DESCRIPTION, 160);
    }

    public function test_a_good_description_passes(): void
    {
        $this->assertSame([], (new SeoMetaCheck)->problems(self::GOOD, self::description(), [self::PAGE]));
        $this->assertSame([self::GOOD, []], (new SeoMetaCheck)->settle(self::GOOD, self::description(), [self::PAGE]));
    }

    public function test_out_of_range(): void
    {
        $check = new SeoMetaCheck;

        $this->assertArrayHasKey(SeoMetaCheck::TOO_SHORT, $check->problems('Winter visits for gardens.', self::description(), [self::PAGE]));
        $this->assertArrayHasKey(SeoMetaCheck::TOO_LONG, $check->problems(self::GOOD.' We leave seed heads standing for the birds.', self::description(), [self::PAGE]));
        $this->assertArrayHasKey(SeoMetaCheck::EMPTY, $check->problems('  ', self::description(), [self::PAGE]));
    }

    public function test_only_too_long_is_cut_at_a_word(): void
    {
        [$text, $problems] = (new SeoMetaCheck)->settle(self::GOOD.' We leave seed heads standing for the birds.', self::description(), [self::PAGE]);

        $this->assertArrayHasKey(SeoMetaCheck::TOO_LONG, $problems);
        $this->assertLessThanOrEqual(155, mb_strlen($text));
        $this->assertGreaterThanOrEqual(120, mb_strlen($text));
        $this->assertStringStartsWith('Monthly winter visits', $text);
        $this->assertDoesNotMatchRegularExpression('/[ ,;:]$/', $text);
    }

    public function test_an_invented_figure_or_name_is_refused(): void
    {
        $check = new SeoMetaCheck;
        $invented = 'Twelve years of monthly winter visits to cut back, divide and mulch established gardens in Cumbria, from November to February, for £180.';

        $problems = $check->problems($invented, self::description(), [self::PAGE]);
        $this->assertArrayHasKey(SeoMetaCheck::UNSOURCED, $problems);
        $this->assertStringContainsString('£180', $problems[SeoMetaCheck::UNSOURCED]);
        $this->assertStringContainsString('Cumbria', $problems[SeoMetaCheck::UNSOURCED]);
        $this->assertSame('', $check->settle($invented, self::description(), [self::PAGE])[0], 'Dropped, not repaired.');
    }

    public function test_a_figure_only_inside_a_marker_is_not_confirmed(): void
    {
        $text = 'Monthly winter visits to cut back, divide and mulch established gardens in 3 areas, from November to February, so the borders come back in spring.';
        $problems = (new SeoMetaCheck)->problems($text, self::description(), [self::PAGE]);

        $this->assertArrayHasKey(SeoMetaCheck::UNCONFIRMED, $problems);
        $this->assertStringContainsString('“3”', $problems[SeoMetaCheck::UNCONFIRMED]);
        $this->assertArrayNotHasKey(SeoMetaCheck::UNSOURCED, $problems);
        $this->assertArrayHasKey(SeoMetaCheck::MARKER, (new SeoMetaCheck)->problems(self::GOOD.' [[ask: price]]', self::description(), [self::PAGE]));
    }

    public function test_plain_text_only(): void
    {
        $check = new SeoMetaCheck;

        foreach (['Monthly winter visits! Cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, November to February.',
            'Monthly winter visits 🌱 to cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, November to February.',
            'MONTHLY winter visits to cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, November to February.',
            "Monthly winter visits to cut back, divide and mulch established gardens\nacross Northumberland, Durham and the Tyne Valley, November to February."] as $text) {
            $this->assertArrayHasKey(SeoMetaCheck::FORMAT, $check->problems($text, self::description(), [self::PAGE]), $text);
        }
    }

    public function test_a_title_is_held_to_the_budget_left_by_the_site_name_and_isnt_the_page_title(): void
    {
        $range = MetaRange::for(SeoField::TITLE, 60, TitleFormat::of('Northfold Gardens', '|', 'after'));
        $check = new SeoMetaCheck;

        $this->assertSame([], $check->problems('Winter care visits for gardens', $range, [self::PAGE], 'Winter care visits'));
        $this->assertArrayHasKey(SeoMetaCheck::TOO_LONG, $check->problems('Winter care visits for established gardens in the North', $range, [self::PAGE]), '57 characters, but 77 with " | Northfold Gardens".');
        $this->assertArrayHasKey(SeoMetaCheck::SAME_AS_TITLE, $check->problems('Winter care visits, monthly from November', $range, [self::PAGE], 'Winter care visits, monthly from November'));
    }
}
