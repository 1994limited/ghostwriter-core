<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefCheck;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BriefCheck (1.6.1): what the person or the given context supplied stays,
 * in whatever form; invented figures and invented speech don't.
 */
final class BriefCheckTest extends TestCase
{
    private const F = BriefCheck::FIGURE;

    private const Q = BriefCheck::QUOTE;

    /**
     * Figures and quotes that came from the person or the context, in
     * another form: each is kept exactly.
     *
     * @return array<string, array{0: string, 1: string, 2?: list<string>}>
     */
    public static function supplied(): array
    {
        return [
            // Quoted titles and names.
            'a quoted title of an entry on the site' => ['Kiln opening', 'Model the tone on "How we rebuilt the Mill kiln".', ['How we rebuilt the Mill kiln', 'Old kiln']],
            'a quoted example title, with figures in it' => ['Kiln opening', 'Like "5 lessons from 2019", but shorter.', ['5 lessons from 2019']],
            'a short quoted title with a figure' => ['Kiln opening', 'A follow-up to "Mill 2".', ['Mill 2']],
            'an entry title named without quotes' => ['Kiln opening', 'A sequel to Mill 2 rebuild, a year on.', ['Mill 2 rebuild']],
            'the working title, quoted' => ['Kiln opening', 'Open on the line "The new kiln opens in May".', ['The new kiln opens in May']],
            'a quoted term the person used' => ['We call it the slow-build approach.', 'Explain the "Slow build approach" in plain words.'],
            'a quoted phrase the person used, curly quotes' => ['Say it’s “a kiln for the whole town”.', 'Lead with “A kiln for the whole town”.'],
            'proposed section headings' => ['Kiln opening', 'Three sections: "Why the old kiln failed", "What we changed", "What visitors see now".'],
            'a proposed title' => ['Kiln opening', 'Something like "A new kiln for an old harbour".'],
            // Lengths the person stated.
            '800-word, then 800 alone' => ['An 800-word piece on the kiln.', 'Length: 800.'],
            'eight hundred, then 800' => ['Eight hundred words, give or take.', 'Aim for 800 or so.'],
            'about 800, then 800' => ['About 800 words.', 'Keep to 800, no more.'],
            'a length as 800-word' => ['Kiln opening', 'An 800-word read.'],
            'a length range' => ['Somewhere between 1,500 and 2,000 words.', 'Somewhere in 1,500–2,000.'],
            'a range given as words' => ['Fifteen to twenty minutes long.', 'A 15-20 minute read; 15 to 20.'],
            'a length from the kind\'s guidance' => ['Kiln opening', 'Max 800.'],
            // Money, percentages, dates.
            'a price in another form' => ['Tickets are £12 and the build cost twelve thousand pounds.', 'Tickets £12; it cost £12k.'],
            'a money range' => ['A budget between £5,000 and £10,000.', 'A £5-10k budget.'],
            'millions' => ['It cost £1.2 million.', 'It cost £1,200,000, or £1.2m.'],
            'a percentage in words' => ['Forty per cent more visitors.', 'Visitors up 40%.'],
            'a date written differently' => ['Opens on 14 May 2026.', 'Opens 2026-05-14 (14/05/2026), on May 14th.'],
            'a year and a team size the person gave' => ['We have worked with them since 2019, a team of twelve.', 'Since 2019, with 12 people.'],
            'a figure inside brackets' => ['Kiln opening', '[Add: the 3 numbers that matter]'],
        ];
    }

    /**
     * Facts nobody gave: each is still taken out.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3?: list<string>}>
     */
    public static function invented(): array
    {
        return [
            'an invented price' => ['Tickets are £12.', 'Tickets cost £15.', 'Tickets cost '.self::F.'.'],
            'an invented price range' => ['A budget of £5,000.', 'A £5-10k budget.', 'A '.self::F.' budget.'],
            'a figure given as money, written as a percentage' => ['Tickets are 40 pounds.', 'Up 40%.', 'Up '.self::F.'.'],
            'an invented year' => ['Kiln opening for the trust.', 'Working together since 2019.', 'Working together since '.self::F.'.'],
            'an invented team size' => ['Kiln opening for the trust.', 'A team of 12 built it.', 'A team of '.self::F.' built it.'],
            'an invented date' => ['Opens in May.', 'Opens on 14 June 2026.', 'Opens on '.self::F.' June '.self::F.'.'],
            'a figure from another entry\'s title, as a fact' => ['Kiln opening', 'Visitors rose 40% here too.', 'Visitors rose '.self::F.' here too.', ['Visitors up 40% at the Mill']],
            'a month that is only a verb' => ['We may open it soon.', 'A team of 5.', 'A team of '.self::F.'.'],
            'an invented client quote' => ['Kiln opening', 'The client said "it changed everything for us".', 'The client said '.self::Q.'.'],
            'an invented testimonial' => ['Kiln opening', 'Testimonial: "Best studio we have ever worked with"', 'Testimonial: '.self::Q],
            'speech attributed after the quote' => ['Kiln opening', '"It was the best day out in years," the director said.', self::Q.' the director said.'],
            'speech that looks like naming' => ['Kiln opening', 'Visitors called it "the best day out in years".', 'Visitors called it '.self::Q.'.'],
            'an unattributed quote nobody gave' => ['Kiln opening', 'Visitors loved it: "a proper day out for the family".', 'Visitors loved it: '.self::Q.'.'],
            'a heading list with speech in it' => ['Kiln opening', 'Sections: "Why we did it". Then the client said "it changed everything".', 'Sections: "Why we did it". Then the client said '.self::Q.'.'],
        ];
    }

    /**
     * @param  list<string>  $titles
     */
    #[DataProvider('supplied')]
    public function test_what_was_supplied_stays(string $source, string $answer, array $titles = []): void
    {
        [$answers, $problems] = BriefCheck::check(self::kind(), ['angle' => $answer], $source, titles: $titles);

        $this->assertSame($answer, $answers['angle']);
        $this->assertSame([], $problems);
    }

    /**
     * @param  list<string>  $titles
     */
    #[DataProvider('invented')]
    public function test_what_was_invented_is_left_for_the_person(string $source, string $answer, string $expected, array $titles = []): void
    {
        [$answers, $problems] = BriefCheck::check(self::kind(), ['angle' => $answer], $source, titles: $titles);

        $this->assertSame($expected, $answers['angle']);
        $this->assertNotSame([], $problems);
    }

    private static function kind(): ContentKind
    {
        return ContentKind::fromArray('project', [
            'title' => 'Project write-up',
            'description' => 'One project, from the brief to the result.',
            'guidance' => 'Lead with the outcome. Keep it under 800 words.',
            'questions' => [['handle' => 'angle', 'label' => 'What is the angle?']],
        ]);
    }
}
