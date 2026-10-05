<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Effort;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapRefused;
use NineteenNinetyFour\Ghostwriter\Core\Studio\GapRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The gap filler: one small request per click, and never a fact.
 */
class GapFillerTest extends StudioTestCase
{
    public function test_a_summary_is_one_request_to_the_gap_filler_from_the_pages_own_text(): void
    {
        $this->fake->respond('gap-filler', self::reply("<result>\n\"Capacitor puts the web app you have in the App Store.\"\n</result>", 30, 12));

        $result = $this->studio()->fillGap(GapRequest::summary('Summary', 'Capacitor wraps the web app you already have in a native shell, so it ships to the App Store.', 80));

        $this->assertSame('Capacitor puts the web app you have in the App Store.', $result->value);
        $this->assertSame(12, $result->usage->output);
        $this->assertCount(1, $this->fake->requests());

        $request = $this->sent('gap-filler');
        $this->assertSame(1500, $request->resolvedMaxTokens());
        $this->assertSame(Effort::Low, $request->resolvedEffort());
        $this->assertStringContainsString('You never add a fact.', $request->instructions);
        $this->assertSame("Task: summary\nField: Summary\nLimit: 80 characters\n\n<page>\nCapacitor wraps the web app you already have in a native shell, so it ships to the App Store.\n</page>", $request->prompt);
    }

    public function test_no_request_is_ever_made_to_fill_a_fact(): void
    {
        $ask = Gap::make(GapKind::Ask, FieldPath::of('intro'), 'Intro', 'adult ticket price', 'Tickets cost [[ask: adult ticket price]].');
        $askValue = Gap::make(GapKind::AskValue, FieldPath::of('price'), 'Price', 'adult ticket price');

        $attempts = [
            fn () => GapRequest::summary('Intro', 'Text', gap: $ask),
            fn () => GapRequest::shorten('Intro', 'Text', 40, $ask),
            fn () => GapRequest::summary('Price', 'Text', gap: $askValue),
            fn () => GapRequest::writeAround($askValue),
            fn () => GapRequest::writeAround(Gap::make(GapKind::Required, FieldPath::of('summary'), 'Summary')),
            fn () => GapRequest::alt('Price', new Image(base64_decode(FakeProvider::PNG), 'image/png'), gap: $askValue),
        ];

        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} should have been refused.");
            } catch (GapRefused $refused) {
                $this->assertSame(422, $refused->status());
            }
        }

        $this->fake->assertNothingSent();
    }

    public function test_a_sentence_is_written_around_a_missing_fact_without_a_vaguer_one(): void
    {
        $gap = Gap::make(GapKind::Ask, FieldPath::of('intro'), 'Intro', 'how long a typical project takes', 'It ships in [[ask: how long a typical project takes]], with no rebuild.');
        $this->fake->respond('gap-filler', self::reply('<result>It ships with no rebuild.</result>'));

        $result = $this->studio()->fillGap(GapRequest::writeAround($gap));

        $this->assertSame('It ships with no rebuild.', $result->value);
        $this->assertSame("Task: write-around\nField: Intro\nMissing: how long a typical project takes\n\n<sentence>\nIt ships in [[ask: how long a typical project takes]], with no rebuild.\n</sentence>", $this->sent('gap-filler')->prompt);
        $this->assertStringContainsString('Do not put a vaguer fact in its place', $this->sent('gap-filler')->instructions);
    }

    public function test_an_answer_that_invents_a_figure_or_keeps_a_marker_is_not_used(): void
    {
        $gap = Gap::make(GapKind::Ask, FieldPath::of('intro'), 'Intro', 'duration', 'It ships in [[ask: duration]] for 2 teams.');

        $replies = ['<result>It ships in 6–10 weeks for 2 teams.</result>', '<result>It ships in [[ask: duration]].</result>', '<result>See [us](#gw-link:contact).</result>'];
        $this->fake->respond('gap-filler', ...[...array_map(fn (string $reply) => self::reply($reply), $replies), self::reply('<result>It ships for 2 teams.</result>')]);

        foreach ($replies as $reply) {
            try {
                $this->studio()->fillGap(GapRequest::writeAround($gap));
                $this->fail("Should not have used: {$reply}");
            } catch (UnreadableReply $exception) {
                $this->assertSame('gap-filler', $exception->agent);
            }
        }

        // Figures the text already has are fine.
        $this->assertSame('It ships for 2 teams.', $this->studio()->fillGap(GapRequest::writeAround($gap))->value);
        $this->assertStringNotContainsString('6–10 weeks', $this->logged(), 'Replies are not logged unless the site asks.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function figuresInAnotherForm(): array
    {
        return [
            'millions' => ['The rebuild cost £1,200,000 over eight weeks.', 'It cost £1.2m over 8 weeks.', true],
            'a date' => ['It opens on 14 May 2026.', 'Opens 2026-05-14.', true],
            'a percentage' => ['Visitors rose by forty per cent.', 'Visitors rose 40%.', true],
            'a range' => ['It takes six to ten weeks.', 'It takes 6–10 weeks.', true],
            'an invented figure' => ['It takes six weeks.', 'It takes 6–10 weeks.', false],
            'an invented price' => ['Tickets are £12.', 'Tickets are £15.', false],
        ];
    }

    #[DataProvider('figuresInAnotherForm')]
    public function test_figures_the_text_has_are_fine_in_any_form(string $text, string $answer, bool $used): void
    {
        $this->fake->respond('gap-filler', self::reply("<result>{$answer}</result>"));

        try {
            $this->assertSame($answer, $this->studio()->fillGap(GapRequest::shorten('Intro', $text, 200))->value);
            $this->assertTrue($used, "Should not have used: {$answer}");
        } catch (UnreadableReply $exception) {
            $this->assertFalse($used, "Should have used: {$answer}");
        }
    }

    public function test_a_shortened_text_is_kept_within_its_limit(): void
    {
        $this->fake->respond('gap-filler', self::reply('<result>Capacitor puts the web product you already have into both app stores without a rebuild, and keeps it there.</result>'));

        $result = $this->studio()->fillGap(GapRequest::shorten('SEO description', 'Already have a web product that works? Capacitor puts it in the App Store and Google Play without a rebuild, and keeps it there.', 60));

        $this->assertLessThanOrEqual(60, mb_strlen($result->value));
        $this->assertStringStartsWith('Capacitor puts the web product', $result->value);
    }

    public function test_a_long_heading_is_shortened_with_its_section_for_context_only(): void
    {
        $heading = 'What to do in a walled garden in late winter, before the first warm weekend arrives';
        $gap = Gap::make(GapKind::HeadingLong, FieldPath::of('body'), 'Body', $heading, $heading, meta: ['target' => 60]);
        $this->fake->respond('gap-filler', self::reply('<result>## Walled garden jobs for late winter.</result>'));

        $result = $this->studio()->fillGap(GapRequest::shortenHeading($gap, 'Cut back the grasses and mulch the borders.'));

        $this->assertSame('Walled garden jobs for late winter', $result->value);
        $this->assertSame("Task: shorten-heading\nField: Body\nLimit: 60 characters\n\n<heading>\n{$heading}\n</heading>\n\n<around>\nCut back the grasses and mulch the borders.\n</around>", $this->sent('gap-filler')->prompt);

        $this->expectException(GapRefused::class);
        GapRequest::shortenHeading(Gap::make(GapKind::Ask, FieldPath::of('body'), 'Body', 'price'));
    }

    public function test_alt_text_sees_the_image_unless_the_guard_refuses_it(): void
    {
        $png = new Image(base64_decode(FakeProvider::PNG), 'image/png');
        $this->fake->respond('gap-filler', self::reply('<result>A potter mending a cracked bowl at a workbench</result>'));

        $result = $this->studio()->fillGap(GapRequest::alt('Featured image', $png, 'Repairs', AssetRef::statamic('assets', 'photos/bowl.png')));

        $this->assertSame('A potter mending a cracked bowl at a workbench', $result->value);
        $this->assertCount(1, $this->sent('gap-filler')->images);

        try {
            $this->studio()->fillGap(GapRequest::alt('Featured image', $png, 'Repairs', AssetRef::statamic('assets', 'stock/GettyImages-123.jpg')));
            $this->fail('A Getty image must not reach a model.');
        } catch (GapRefused $refused) {
            $this->assertStringContainsString('Describe it yourself', $refused->getMessage());
        }

        $this->assertCount(1, $this->fake->requests());
    }
}
