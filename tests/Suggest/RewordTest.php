<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RewordRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use PHPUnit\Framework\TestCase;

/** "Write another": one small reworder call, validated like the first. */
final class RewordTest extends TestCase
{
    private function voiceSuggestion(): Suggestion
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', ReviewCase::reply());
        $input = ReviewCase::input();
        $review = (new SuggestionValidator)->validate(ReviewCase::studio($fake)->suggestEdits($input)->value, $input);

        foreach ($review->suggestions as $suggestion) {
            if ($suggestion->category->value === 'voice') {
                return $suggestion;
            }
        }

        $this->fail('No voice suggestion.');
    }

    public function test_one_reworder_call_with_the_sentence_and_every_version_shown(): void
    {
        $suggestion = $this->voiceSuggestion();
        $fake = new FakeProvider;
        $fake->respond('reworder', "<versions>\n<version>We plan gardens and help them grow</version>\n<version>We design gardens and help them grow</version>\n</versions>");

        $request = RewordRequest::for($suggestion, Northfold::context(), ReviewCase::VOICE);
        $versions = ReviewCase::studio($fake)->reword($request)->value;

        $this->assertSame(['We plan gardens and help them grow'], $versions, 'A version already shown is not offered again.');
        $this->assertCount(1, $fake->requests());
        $fake->assertSent('reworder', fn (TextRequest $r) => str_contains($r->prompt, '<quote>We leverage our expertise to deliver bespoke garden solutions</quote>')
            && str_contains($r->prompt, '- Gardens designed, planted and looked after')
            && str_contains($r->instructions, 'What this voice never does')
            && $r->resolvedMaxTokens() === 1500 && $r->resolvedEffort()?->value === 'low');
    }

    public function test_a_new_version_passes_the_same_checks_as_the_first(): void
    {
        $suggestion = $this->voiceSuggestion();
        $validator = new SuggestionValidator;
        $input = ReviewCase::input();

        $this->assertTrue($validator->acceptsVersion($suggestion, 'We plan gardens and help them grow', $input));
        $this->assertFalse($validator->acceptsVersion($suggestion, 'We have planned 400 gardens since 1990', $input), 'An invented figure.');
        $this->assertFalse($validator->acceptsVersion($suggestion, 'See [us](https://example.com)', $input), 'A new outside link.');
    }

    public function test_only_wording_can_be_written_again(): void
    {
        $fact = (new SuggestionValidator)->validate(new SuggestionReply, ReviewCase::input())->suggestions[1];

        $this->expectException(\InvalidArgumentException::class);
        RewordRequest::for($fact, Northfold::context());
    }
}
