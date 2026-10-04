<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quiet;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReasonSource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewPrompt;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestOptions;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ValidatedReview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One fixture per rule of the validator (design §6.4): what is kept,
 * what is dropped and why. Facts are never invented: anything that adds
 * one is dropped, never repaired.
 */
final class ValidatorTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function validate(array $items, ?ReviewInput $input = null): ValidatedReview
    {
        $input ??= ReviewCase::input();

        return (new SuggestionValidator)->validate(new SuggestionReply(array_map(fn (array $item) => ['batch' => 0, 'item' => $item], $items)), $input);
    }

    /** The model's own suggestions kept, by category. @return list<Suggestion> */
    private static function own(ValidatedReview $review): array
    {
        return array_values(array_filter($review->suggestions, fn (Suggestion $s) => ! $s->free && $s->finding === null));
    }

    private const VOICE = ['category' => 'voice', 'unit' => 'u3', 'quote' => 'We leverage our expertise to deliver bespoke garden solutions', 'reason' => 'Jargon.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => 'We design gardens and help them grow'];

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function drops(): iterable
    {
        yield 'kept as it is' => [self::VOICE, null];
        yield 'anchor: an unknown unit' => [['unit' => 'u99'] + self::VOICE, 'anchor'];
        yield 'anchor: a quote that is not there' => [['quote' => 'We leverage our considerable expertise'] + self::VOICE, 'anchor'];
        yield 'anchor: across two paragraphs' => [['category' => 'clarity', 'unit' => 'u4', 'quote' => 'Garden design A full design', 'replacement' => 'Garden design: a full design', 'source' => ['kind' => 'general']] + self::VOICE, 'anchor'];
        yield 'facts: an invented figure' => [['replacement' => 'We have designed 300 gardens since 1998'] + self::VOICE, 'facts'];
        yield 'facts: an invented name' => [['replacement' => 'We design gardens across Cumbria and Yorkshire'] + self::VOICE, 'facts'];
        yield 'link: a new link to another site' => [['replacement' => 'We design [gardens](https://example.com)'] + self::VOICE, 'link'];
        yield 'link: an entry not in the digest' => [['replacement' => 'We design [gardens](entry:e99)'] + self::VOICE, 'link'];
        yield 'size: far longer' => [['replacement' => 'We design gardens, plant them, look after them in every season and help them grow for as long as you own them and longer'] + self::VOICE, 'size'];
        yield 'scope: no replacement' => [array_diff_key(self::VOICE, ['replacement' => 1]), 'scope'];
        yield 'facts: a template that changes two spans' => [['category' => 'fact-to-check', 'unit' => 'u4', 'quote' => 'our team of 6 designers', 'reason' => 'Check.', 'source' => ['kind' => 'general'], 'fact' => ['ask' => 'Designers', 'template' => 'our {answer} of 6 people']], 'facts'];
        yield 'unreadable: an unknown category' => [['category' => 'tone'] + self::VOICE, 'unreadable'];
        yield 'finding: a finding that does not exist' => [['finding' => 'f42'] + self::VOICE, 'finding'];
        yield 'finding: declining a date' => [['finding' => 'f1', 'category' => 'out-of-date', 'decline' => 'It is fine.'], 'finding'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    #[DataProvider('drops')]
    public function test_each_rule(array $item, ?string $dropped): void
    {
        $review = $this->validate([$item]);

        if ($dropped === null) {
            $this->assertCount(1, self::own($review));
            $this->assertSame([], $review->dropped);

            return;
        }

        $this->assertSame([], self::own($review), 'Dropped.');
        $this->assertSame([$dropped => 1], $review->dropped);
        $this->assertCount(5, $review->suggestions, 'The free findings still stand.');
    }

    public function test_a_fuzzy_quote_is_requoted_to_the_real_text(): void
    {
        $review = $this->validate([['quote' => 'We leverage our expertise to deliver bespoke gardening solutions'] + self::VOICE]);

        $this->assertSame('We leverage our expertise to deliver bespoke garden solutions', self::own($review)[0]->anchor->quote?->exact);
    }

    public function test_a_finding_takes_its_own_anchor_and_category(): void
    {
        $review = $this->validate([['finding' => 'f1', 'category' => 'voice', 'unit' => 'u3', 'quote' => 'bespoke', 'reason' => 'Old.', 'source' => ['kind' => 'finding'], 'replacement' => 'Every winter']]);
        $fix = $review->suggestions[0];

        $this->assertSame(Category::OutOfDate, $fix->category);
        $this->assertSame('New for 2024', $fix->anchor->quote?->exact);
        $this->assertFalse($fix->free);
    }

    public function test_a_finding_named_twice_keeps_the_first(): void
    {
        $fix = ['finding' => 'f1', 'category' => 'out-of-date', 'reason' => 'Old.', 'source' => ['kind' => 'finding']];
        $review = $this->validate([$fix + ['replacement' => 'Every winter'], $fix + ['replacement' => 'Each winter']]);

        $this->assertSame('Every winter', $review->suggestions[0]->replacement);
        $this->assertSame(['finding' => 1], $review->dropped);
    }

    public function test_a_clarity_hint_may_be_declined(): void
    {
        $review = $this->validate([['finding' => 'f3', 'category' => 'clarity', 'decline' => 'It reads fine.']]);

        $this->assertSame(['declined' => 1], $review->dropped);
        $this->assertNotContains('clarity', array_map(fn (Suggestion $s) => $s->category->value, $review->suggestions));
    }

    public function test_a_fact_to_check_never_keeps_a_replacement_and_its_without_adds_nothing(): void
    {
        $review = $this->validate([['finding' => 'f2', 'category' => 'fact-to-check', 'reason' => 'Check.', 'source' => ['kind' => 'finding'], 'replacement' => 'team of 8', 'fact' => ['ask' => 'Designers', 'template' => 'team of {answer}', 'without' => 'team of eight', 'answer' => 'number']]]);
        $fact = $review->suggestions[1];

        $this->assertNull($fact->replacement);
        $this->assertNull($fact->fact?->without, 'A version without that adds a figure is left out.');
        $this->assertSame('team of 9', $fact->fact?->fill('9'));
        $this->expectException(\InvalidArgumentException::class);
        $fact->fact?->fill('8 or 9?');
    }

    public function test_claims_flagged_unless_the_site_switch_is_off(): void
    {
        $claim = ['category' => 'fact-to-check', 'unit' => 'u3', 'quote' => 'our expertise', 'reason' => 'Can you show it?', 'source' => ['kind' => 'general'], 'fact' => ['ask' => 'What expertise?', 'template' => 'our {answer}', 'answer' => 'text']];

        $this->assertCount(1, self::own($this->validate([$claim])));

        $off = ReviewCase::input(Northfold::context(options: new SuggestOptions(claims: false)));
        $review = $this->validate([$claim], $off);
        $this->assertSame([], self::own($review));
        $this->assertSame(['claims' => 1], $review->dropped);
        $this->assertStringContainsString('turned claim checks off', ReviewCase::studio(new FakeProvider)->reviewerInstructions($off));
    }

    public function test_a_heading_the_guide_does_not_have_becomes_general(): void
    {
        $review = $this->validate([['source' => ['kind' => 'voice-guide', 'heading' => 'Never say bespoke']] + self::VOICE]);

        $this->assertSame(ReasonSource::General, self::own($review)[0]->reason->source);
        $this->assertSame('Jargon.', self::own($review)[0]->reason->text, 'The reason is kept.');
    }

    public function test_no_voice_guide_no_voice_suggestions(): void
    {
        $review = $this->validate([self::VOICE], ReviewCase::input(voice: ''));

        $this->assertSame(['voice' => 1], $review->dropped);
    }

    public function test_what_was_dismissed_is_not_suggested_again(): void
    {
        $first = self::own($this->validate([self::VOICE]))[0];
        $quieted = new Quieted([Quiet::of($first->id, $first->anchor, new DateTimeImmutable(Northfold::NOW))]);
        $input = ReviewCase::input(Northfold::context(quieted: $quieted));

        $this->assertSame(['dismissed' => 1], $this->validate([self::VOICE], $input)->dropped);
        $this->assertStringContainsString("<dismissed>\nvoice u3 \"we leverage our expertise to deliver bespoke garden solutions\"\n</dismissed>", ReviewPrompt::render($input, $input->batches()[0]));
    }

    public function test_overlaps_a_finding_beats_the_models_own_and_rank_decides_the_rest(): void
    {
        $review = $this->validate([
            ['category' => 'clarity', 'unit' => 'u2', 'quote' => 'New for 2024: winter care visits', 'reason' => 'Wordy.', 'source' => ['kind' => 'general'], 'replacement' => 'Winter care visits'],
            ['category' => 'voice', 'unit' => 'u3', 'quote' => 'deliver bespoke garden solutions', 'reason' => 'Jargon.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => 'design gardens'],
            ['category' => 'clarity', 'unit' => 'u3', 'quote' => 'We leverage our expertise', 'reason' => 'Wordy.', 'source' => ['kind' => 'general'], 'replacement' => 'We use what we know'],
            ['category' => 'clarity', 'unit' => 'u3', 'quote' => 'bespoke garden', 'reason' => 'Wordy.', 'source' => ['kind' => 'general'], 'replacement' => 'made-to-measure garden'],
        ]);

        $this->assertSame(['overlap' => 2], $review->dropped, 'The eyebrow loses to its finding; the voice suggestion loses to the clarity one inside it (Clarity ranks before Voice).');
        $this->assertSame(['We leverage our expertise', 'bespoke garden'], array_map(fn (Suggestion $s) => $s->anchor->quote?->exact, self::own($review)));
    }

    public function test_at_most_three_in_a_field(): void
    {
        $words = ['We leverage', 'our expertise', 'to deliver', 'bespoke garden solutions'];
        $items = array_map(fn (string $quote) => ['category' => 'clarity', 'unit' => 'u3', 'quote' => $quote, 'reason' => 'Wordy.', 'source' => ['kind' => 'general'], 'replacement' => $quote], $words);

        $this->assertSame(['cap' => 1], $this->validate($items)->dropped);
    }

    public function test_alternatives_that_add_a_fact_are_dropped_alone(): void
    {
        $review = $this->validate([['alternatives' => ['We design gardens for 40 clients a year', 'We design and plant gardens']] + self::VOICE]);

        $this->assertSame(['We design and plant gardens'], self::own($review)[0]->alternatives);
    }

    public function test_alt_text_is_clipped_and_loses_image_of(): void
    {
        $review = $this->validate([['finding' => 'f5', 'category' => 'accessibility', 'unit' => 'i1', 'reason' => 'x', 'source' => ['kind' => 'image'], 'replacement' => 'A picture of '.str_repeat('stone and gravel ', 12)]]);
        $alt = $review->suggestions[3]->replacement ?? '';

        $this->assertStringStartsWith('Stone and gravel', $alt);
        $this->assertLessThanOrEqual(SuggestionValidator::ALT_LIMIT, mb_strlen($alt));
    }

    public function test_an_seo_rewrite_must_fit_its_limit(): void
    {
        $review = $this->validate([['finding' => 'f6', 'category' => 'seo', 'unit' => 'u6', 'reason' => 'x', 'source' => ['kind' => 'finding'], 'replacement' => Northfold::SEO]]);

        $this->assertSame(['size' => 1], $review->dropped);
    }
}
