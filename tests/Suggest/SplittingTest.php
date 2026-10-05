<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use PHPUnit\Framework\TestCase;

/**
 * Long pages are split into several calls, so the whole page is reviewed:
 * the confirm says how many before anything runs, and the replies are
 * merged into one review.
 */
final class SplittingTest extends TestCase
{
    private static function section(int $n, string $extra = ''): string
    {
        return "## Part {$n}\n\n".trim(str_repeat("Paragraph {$n} talks about the garden in plain words. ", 40).$extra);
    }

    private static function input(int $wordsPerCall): ReviewInput
    {
        // It links to the site, so the only candidate is the out-of-date one.
        $body = implode("\n\n", [self::section(1, 'See [our services](/services).'), self::section(2), self::section(3, 'New for 2024: winter visits.')]);
        $schema = new Schema([new Field('title', Kind::Text, 'Title'), new Field('intro', Kind::Text, 'Intro'), new Field('body', Kind::RichText, 'Body', type: 'markdown'), new Field('summary', Kind::LongText, 'Summary')]);
        $entry = new EntryData(['title' => 'A long guide', 'intro' => 'Short intro.', 'body' => $body, 'summary' => 'A summary.']);
        $context = new CheckContext(gaps: new GapContext(schema: $schema, entry: $entry, richText: new MarkdownDialect), now: new DateTimeImmutable(Northfold::NOW), updatedAt: new DateTimeImmutable(Northfold::UPDATED));

        return new ReviewInput($context, ReviewCase::writer(), Findings::standard()->find($context), wordsPerCall: $wordsPerCall);
    }

    public function test_a_short_page_is_one_call(): void
    {
        $this->assertSame(1, self::input(ReviewInput::WORDS_PER_CALL)->calls());
    }

    public function test_a_long_page_is_split_by_units_in_reading_order(): void
    {
        $input = self::input(700);
        $batches = $input->batches();

        $this->assertSame(3, $input->calls());
        $this->assertSame([['u1', 'u2', 'u3'], ['u4'], ['u5', 'u6']], array_map(fn ($batch) => array_map(fn (Unit $unit) => $unit->id, $batch->units), $batches));
        $this->assertSame(['f1'], array_keys($batches[2]->findings), 'The finding goes with the part it is in.');
        $this->assertSame([], $batches[0]->findings);
    }

    public function test_each_part_is_its_own_call_and_the_replies_merge(): void
    {
        $input = self::input(700);
        $fake = new FakeProvider;
        $fake->respond('reviewer',
            '<suggestions>{"suggestions": [{"category": "clarity", "unit": "u3", "quote": "Paragraph 1 talks about the garden in plain words.", "occurrence": 0, "reason": "Repeats.", "source": {"kind": "general"}, "replacement": "Paragraph 1 is about the garden."}]}</suggestions>',
            '<suggestions>{"suggestions": []}</suggestions>',
            '<suggestions>{"suggestions": [{"finding": "f1", "category": "out-of-date", "reason": "Old.", "source": {"kind": "finding"}, "replacement": "Winter visits."}]}</suggestions>',
        );

        $result = ReviewCase::studio($fake)->suggestEdits($input);
        $review = (new SuggestionValidator)->validate($result->value, $input);

        $this->assertCount(3, $fake->requests(), 'One reviewer call per part.');
        $this->assertSame(3, $result->value->calls);
        $fake->assertSent('reviewer', fn (TextRequest $r) => str_contains($r->prompt, 'part="2 of 3"') && ! str_contains($r->prompt, 'id="u3"'));
        $this->assertSame(1, count(array_unique(array_map(fn (TextRequest $r) => $r->instructions, $fake->requests()))), 'The same instructions every call.');
        $this->assertStringContainsString('reviewed in parts', $fake->requests()[0]->instructions);
        $this->assertSame(['Paragraph 1 is about the garden.', 'Winter visits.'], array_map(fn (Suggestion $s) => $s->replacement, $review->suggestions));
    }
}
