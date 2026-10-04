<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use PHPUnit\Framework\TestCase;

/**
 * Suggest edits with structured output: the reviewer and the verifier are
 * sent their reply's schema, the reply comes back as bare JSON and reads as
 * the tagged one did, and an unreadable reply is asked for once more.
 */
final class StructuredReviewTest extends TestCase
{
    /** @return array<string, mixed> The good reply's object, each item with notes first, as a model held to the schema writes it. */
    private static function reply(): array
    {
        preg_match('/<suggestions>(.*)<\/suggestions>/s', ReviewCase::reply(), $m);
        $data = json_decode($m[1], true);

        $data['suggestions'] = array_map(fn (array $item) => ['notes' => 'Checked in its paragraph.'] + $item, $data['suggestions']);

        return $data;
    }

    public function test_the_reviewer_is_sent_its_schema_and_asked_for_bare_json(): void
    {
        $fake = (new FakeProvider)->respondStructured('reviewer', self::reply());
        $input = ReviewCase::input();

        $result = ReviewCase::studio($fake)->suggestEdits($input);
        $request = $fake->prompted('reviewer')[0];

        $this->assertSame(Studio::reviewerSchema(), $request->schema);
        $this->assertStringContainsString('Your reply is JSON in the shape you are given', $request->instructions);
        $this->assertStringNotContainsString('<suggestions>', $request->instructions);
        $this->assertStringNotContainsString("\n\n\n", $request->instructions);
        $this->assertSame([], $result->value->problems);
        $this->assertCount(7, $result->value->items);
        $this->assertSame('Checked in its paragraph.', $result->value->items[0]['item']['notes']);

        $review = (new SuggestionValidator)->validate($result->value, $input);
        $this->assertCount(7, $review->suggestions, 'The same suggestions as the tagged reply: validation is unchanged.');
    }

    public function test_without_structured_output_the_prompt_asks_for_tags_and_the_tagged_reply_reads(): void
    {
        $fake = (new FakeProvider)->withoutStructuredOutput()->respond('reviewer', ReviewCase::reply());

        $result = ReviewCase::studio($fake)->suggestEdits(ReviewCase::input());
        $request = $fake->prompted('reviewer')[0];

        $this->assertStringContainsString("Only this, with nothing before or after it:\n\n<suggestions>\n{\"suggestions\"", $request->instructions);
        $this->assertStringContainsString("]}\n</suggestions>", $request->instructions);
        $this->assertCount(7, $result->value->items);
    }

    public function test_an_unreadable_reply_is_asked_for_once_more_with_the_problem_quoted(): void
    {
        $fake = (new FakeProvider)->respond('reviewer', 'Nothing to change here, the page reads well.', (string) json_encode(self::reply()));

        $result = ReviewCase::studio($fake)->suggestEdits(ReviewCase::input());
        $sent = $fake->prompted('reviewer');

        $this->assertCount(2, $sent);
        $this->assertStringEndsWith("\n\nYour last answer to this couldn't be read: the JSON did not parse. Answer again, in full, exactly in the format asked.", $sent[1]->prompt);
        $this->assertStringStartsWith($sent[0]->prompt, $sent[1]->prompt, 'The same prompt, with the problem after it.');
        $this->assertSame($sent[0]->instructions, $sent[1]->instructions, 'The same instructions, so the cache holds.');
        $this->assertSame($sent[0]->images, $sent[1]->images);
        $this->assertSame([], $result->value->problems);
        $this->assertCount(7, $result->value->items);
        $this->assertSame([200, 100], [$result->usage->input, $result->usage->output], 'Both calls are counted.');
    }

    public function test_a_reply_unreadable_twice_gives_up_after_the_second(): void
    {
        $fake = (new FakeProvider)->respond('reviewer', 'Still no JSON.');

        $result = ReviewCase::studio($fake)->suggestEdits(ReviewCase::input());

        $this->assertCount(2, $fake->prompted('reviewer'));
        $this->assertSame(['the JSON did not parse'], $result->value->problems);
    }

    public function test_the_verifier_reads_a_strict_reply_with_nulls_as_left_out(): void
    {
        $fake = (new FakeProvider)->respond('reviewer', ReviewCase::reply());
        $input = ReviewCase::input();
        $studio = ReviewCase::studio($fake);
        $review = (new SuggestionValidator)->validate($studio->suggestEdits($input)->value, $input);

        $fake->respond('verifier', function (TextRequest $request): string {
            preg_match_all('/<suggestion id="(s\d+)"/', $request->prompt, $m);

            return (string) json_encode(['verdicts' => array_map(fn (string $id) => ['notes' => 'Fine in context.', 'id' => $id, 'verdict' => $id === 's2' ? 'fix' : 'keep', 'reason' => 'Fine.', 'replacement' => $id === 's2' ? 'We design and plant gardens' : null, 'alternatives' => null], $m[1])]);
        });

        $result = $studio->verifyEdits($input, $review->suggestions);
        $request = $fake->prompted('verifier')[0];

        $this->assertSame(Studio::verifierSchema(), $request->schema);
        $this->assertStringNotContainsString('<verdicts>', $request->instructions);
        $this->assertArrayNotHasKey('replacement', $result->value->items[0]['item'], 'A null is read as left out.');

        $verified = (new SuggestionValidator)->verify($review, $result->value, $input);
        $this->assertSame('We design and plant gardens', $verified->suggestions[1]->replacement);
        $this->assertSame(['Gardens designed, planted and looked after'], $verified->suggestions[1]->alternatives, 'Null alternatives keep the reviewer\'s, less the one that is now the replacement.');
        $this->assertSame('keep', $verified->verified[$verified->suggestions[0]->id]);
    }

    public function test_the_fake_can_make_a_reply_up_from_the_schema(): void
    {
        $fake = (new FakeProvider)->respond('reviewer', ReviewCase::reply())->respondFromSchema('verifier');
        $input = ReviewCase::input();
        $studio = ReviewCase::studio($fake);
        $review = (new SuggestionValidator)->validate($studio->suggestEdits($input)->value, $input);

        $result = $studio->verifyEdits($input, $review->suggestions);

        $this->assertSame([], $result->value->problems);
        $this->assertSame(['notes' => 'text', 'id' => 'text', 'verdict' => 'keep', 'reason' => 'text', 'replacement' => 'text', 'alternatives' => ['text']], $result->value->items[0]['item']);
    }
}
