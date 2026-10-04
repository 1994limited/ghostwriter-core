<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReader;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ValidatedReview;
use PHPUnit\Framework\TestCase;

/**
 * The verifier, the second pass: one `verifier` call per part that kept
 * anything, with each suggestion in its paragraph; its verdicts applied
 * by SuggestionValidator::verify().
 */
final class VerifyTest extends TestCase
{
    private static function validated(): ValidatedReview
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', ReviewCase::reply());
        $input = ReviewCase::input();

        return (new SuggestionValidator)->validate(ReviewCase::studio($fake)->suggestEdits($input)->value, $input);
    }

    public function test_the_prompt_is_pinned(): void
    {
        $fake = new FakeProvider;
        $fake->respond('verifier', ReviewCase::verifier());
        $result = ReviewCase::studio($fake)->verifyEdits(ReviewCase::input(), self::validated()->suggestions);

        $this->assertSame(1, $result->value->calls);
        $this->assertCount(7, $result->value->items);

        $record = RequestLog::records($fake->requests())[0];
        $path = dirname(__DIR__).'/Fixtures/suggest/verifier-services.json';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            file_put_contents($path, $json);
        }

        $this->assertSame((string) file_get_contents($path), $json, 'Write the fixture with GHOSTWRITER_UPDATE_FIXTURES=1.');
        $this->assertSame(12000, $record['maxTokens']);
        $this->assertSame('high', $record['effort']);
    }

    public function test_nothing_kept_no_verifier_call(): void
    {
        $fake = new FakeProvider;
        $result = ReviewCase::studio($fake)->verifyEdits(ReviewCase::input(), []);

        $this->assertSame(0, $result->value->calls);
        $fake->assertNothingSent();
    }

    public function test_a_fix_goes_through_the_checks_again(): void
    {
        $input = ReviewCase::input();
        $review = self::validated();
        $verdicts = fn (array $item) => new SuggestionReply([['batch' => 0, 'item' => $item]]);
        $validator = new SuggestionValidator;

        $invented = $validator->verify($review, $verdicts(['id' => 's2', 'verdict' => 'fix', 'replacement' => 'We have designed 300 gardens since 1998', 'reason' => 'x']), $input);
        $this->assertSame(['facts' => 1], $invented->dropped, 'A fix that adds a fact drops the suggestion.');
        $this->assertCount(6, $invented->suggestions);
        $this->assertSame([], $invented->checked, 'Not checked: it may come back.');

        $alternatives = $validator->verify($review, $verdicts(['id' => 's2', 'verdict' => 'fix', 'replacement' => 'We design and plant gardens', 'alternatives' => ['we plan gardens', 'Gardens designed and planted'], 'reason' => 'x']), $input);
        $this->assertSame('We design and plant gardens', $alternatives->suggestions[1]->replacement);
        $this->assertSame(['Gardens designed and planted'], $alternatives->suggestions[1]->alternatives, 'An alternative that fails the fit check is dropped alone.');

        $fact = $validator->verify($review, $verdicts(['id' => 's3', 'verdict' => 'fix', 'replacement' => 'team of 8', 'reason' => 'x']), $input);
        $this->assertNull($fact->suggestions[2]->replacement, 'A fact to check has no words to fix: kept as it was.');
        $this->assertSame('keep', $fact->verified[$fact->suggestions[2]->id]);

        $nonsense = $validator->verify($review, $verdicts(['id' => 's9', 'verdict' => 'drop']), $input);
        $this->assertCount(7, $nonsense->suggestions, 'A verdict on nothing changes nothing.');
    }

    public function test_the_reader_reads_verdicts(): void
    {
        $read = (new SuggestionReader('verdicts'))->read("```json\n{\"verdicts\": [{\"id\": \"s1\", \"verdict\": \"keep\", \"reason\": \"Fine.\"},]}\n```");

        $this->assertNull($read['problem']);
        $this->assertSame([['id' => 's1', 'verdict' => 'keep', 'reason' => 'Fine.']], $read['items']);
        $this->assertSame('there was no "verdicts" list', (new SuggestionReader('verdicts'))->read('{"suggestions": []}')['problem']);
    }
}
