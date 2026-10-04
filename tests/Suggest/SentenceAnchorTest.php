<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use PHPUnit\Framework\TestCase;

/**
 * Out of date findings are anchored on their sentence, so the review call
 * rewrites the sentence as a whole and never pastes words into it ("Every
 * year: winter care visits"). The dated words stay in `meta['phrase']`.
 */
final class SentenceAnchorTest extends TestCase
{
    /**
     * @return list<Finding>
     */
    private static function outOfDate(string $text, string $type = 'markdown', string $updated = FreeChecksTest::UPDATED): array
    {
        $kind = $type === 'text' ? Kind::Text : Kind::LongText;
        $context = new CheckContext(
            gaps: new GapContext(schema: new Schema([new Field('body', $kind, 'Body', type: $type)]), entry: new EntryData(['body' => $text])),
            now: new DateTimeImmutable(FreeChecksTest::NOW),
            updatedAt: new DateTimeImmutable($updated),
            language: 'en',
        );

        return array_values(array_filter(Findings::standard()->find($context), fn (Finding $finding) => in_array($finding->kind, ['past-year', 'relative-time'], true)));
    }

    public function test_a_short_value_is_its_own_sentence(): void
    {
        [$finding] = self::outOfDate('New for 2023: winter care visits', 'text');

        $this->assertSame('New for 2023: winter care visits', $finding->anchor->quote?->exact, 'The whole value.');
        $this->assertSame('New for 2023', $finding->meta['phrase']);
        $this->assertSame(0, $finding->meta['phraseOffset']);
        $this->assertSame('Says “New for 2023” in 2026.', $finding->message->english(), 'The message quotes the dated words.');
        $this->assertSame('out-of-date|body|new for 2023 winter care visits|0', $finding->id);
    }

    public function test_a_phrase_in_the_middle_of_a_sentence(): void
    {
        [$finding] = self::outOfDate("We plan gardens.\n\nOur prices, as of 2023, start at £450. Book a visit today.");

        $this->assertSame('Our prices, as of 2023, start at £450.', $finding->anchor->quote?->exact);
        $this->assertSame('as of 2023', $finding->meta['phrase']);
        $this->assertSame(12, $finding->meta['phraseOffset']);
        $this->assertSame('as of 2023', mb_substr($finding->anchor->quote->exact, $finding->meta['phraseOffset'], 10));
    }

    public function test_a_markdown_heading_is_a_sentence(): void
    {
        $text = "## New for 2023\n\nWinter care visits, from November to February.";
        [$finding] = self::outOfDate($text);

        $this->assertSame('New for 2023', $finding->anchor->quote?->exact, 'Only the heading, not the paragraph after it.');
        $this->assertNotNull((new QuoteFinder)->find($finding->anchor->quote, $text, 0, markdown: true), 'Found again in the field as stored.');
    }

    public function test_one_finding_a_sentence(): void
    {
        $findings = self::outOfDate('New for 2023: this year we are planting natives, as of 2022. Next spring we open.', updated: '2024-03-14');

        $this->assertSame(['New for 2023: this year we are planting natives, as of 2022.', 'Next spring we open.'], array_map(fn (Finding $f) => $f->anchor->quote?->exact, $findings));
        $this->assertSame(['past-year', 'relative-time'], array_map(fn (Finding $f) => $f->kind, $findings), 'A past year and a time word in one sentence: one Out of date finding.');
    }

    public function test_a_long_sentence_is_cut_at_words_around_the_phrase(): void
    {
        $sentence = str_repeat('We look after gardens of every size across the county, ', 6).'and as of 2023 we also plant hedges and '.str_repeat('trees in the spring and autumn for our clients, ', 4).'whatever the weather.';
        [$finding] = self::outOfDate($sentence);
        $exact = $finding->anchor->quote->exact ?? '';

        $this->assertLessThanOrEqual(TextQuote::MAX_EXACT, mb_strlen($exact));
        $this->assertSame('as of 2023', mb_substr($exact, $finding->meta['phraseOffset'], 10));
        $this->assertStringNotContainsString('  ', $exact);
        $this->assertSame($exact, trim($exact), 'Cut at words.');
    }
}
