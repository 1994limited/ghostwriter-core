<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\GapRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Question;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RewordRequest;

/**
 * The kind finder, the planner, the brief writer and filler, the reworder
 * and the gap filler with structured output: each sent its reply's
 * schema, asked for JSON rather than tags, read from the decoded reply,
 * and asked once more when the reply can't be read.
 */
final class StructuredJobsTest extends StudioTestCase
{
    private static function kind(): ContentKind
    {
        return new ContentKind('project', 'Project', 'A project.', 'Say what we did.', [], [
            new Question('reader', 'Who is it for?', required: true),
            new Question('what-to-avoid', 'What must not appear?'),
            new Question('tone of voice', 'How should it sound?', options: ['warm' => 'Warm', 'plain' => 'Plain']),
        ]);
    }

    public function test_the_kind_finder_gets_a_schema_of_the_samples_and_reads_the_kinds(): void
    {
        $this->fake->respondStructured('kind-finder', ['kinds' => [
            ['why' => 'Two entries announce events.', 'title' => 'Event', 'description' => 'An event.', 'examples' => ['a', 'b']],
        ]]);
        $survey = new KindSurvey('Articles', 'articles', [new KindSample('a', 'A'), new KindSample('b', 'B'), new KindSample('c', 'C')]);

        $kinds = $this->studio()->suggestKinds($survey)->value;
        $request = $this->fake->prompted('kind-finder')[0];
        $item = $request->schema?->schema['properties']['kinds']['items'];

        $this->assertSame(['why', 'title', 'description', 'examples'], array_keys($item['properties']), 'The evidence first.');
        $this->assertSame(['a', 'b', 'c'], $item['properties']['examples']['items']['enum'], 'Only the samples shown.');
        $this->assertStringContainsString('as the `kinds` list of the JSON you are given the shape of', $request->instructions);
        $this->assertStringNotContainsString('<kinds>', $request->instructions);
        $this->assertStringNotContainsString('{{', $request->instructions);
        $this->assertSame(['Event', ['a', 'b']], [$kinds[0]->title, $kinds[0]->examples]);
    }

    public function test_an_empty_kinds_list_is_nothing_to_add(): void
    {
        $this->fake->respondStructured('kind-finder', ['kinds' => []]);

        $kinds = $this->studio()->suggestKinds(new KindSurvey('Articles', 'articles', [new KindSample(1, 'A'), new KindSample(2, 'B')]));

        $this->assertSame([], $kinds->value);
        $this->assertCount(1, $this->fake->prompted('kind-finder'));
    }

    public function test_the_planner_gets_the_group_key_and_handles_and_reads_the_ideas(): void
    {
        $this->fake->respondStructured('planner', ['ideas' => [
            ['why' => 'Nothing on pricing.', 'title' => 'What a garden costs', Vocabulary::craft()->groupKey => 'journal', 'type' => '', 'notes' => 'Ranges, not prices.'],
        ]]);
        $context = new PlanContext([new PlanGroup('Journal', 'journal', [new ContentKind('guide', 'Guide')])]);

        $ideas = $this->studio(Vocabulary::craft())->suggestIdeas($context)->value;
        $schema = $this->fake->prompted('planner')[0]->schema?->schema['properties']['ideas']['items'];

        $key = Vocabulary::craft()->groupKey;
        $this->assertSame(['why', 'title', $key, 'type', 'notes'], array_keys($schema['properties']));
        $this->assertSame(['journal'], $schema['properties'][$key]['enum']);
        $this->assertSame('What a garden costs', $ideas[0]->title);
        $this->assertNull($ideas[0]->kind, 'An empty type is none.');
        $this->assertStringContainsString('or an empty string if none fits', $this->fake->prompted('planner')[0]->instructions);
    }

    public function test_the_brief_schema_has_every_answer_required_under_a_safe_key(): void
    {
        $schema = Studio::briefSchema(self::kind(), true);

        $this->assertSame(['reader' => 'reader', 'what-to-avoid' => 'what_to_avoid', 'tone of voice' => 'tone_of_voice'], Studio::briefKeys(self::kind()));
        $this->assertSame(['title', 'answers'], $schema->schema['required']);
        $this->assertSame(['reader', 'what_to_avoid', 'tone_of_voice'], $schema->schema['properties']['answers']['required']);
        $this->assertSame(['warm', 'plain', ''], $schema->schema['properties']['answers']['properties']['tone_of_voice']['enum']);
        $this->assertSame(['reader', 'what_to_avoid', 'tone_of_voice'], Schemas::anthropic($schema)['properties']['answers']['required'], 'No optional answers: Claude allows 24 in a request.');
    }

    public function test_the_brief_filler_lists_the_safe_keys_and_maps_the_answers_back(): void
    {
        $this->fake->respondStructured('brief-filler', ['title' => "A garden\n for a family", 'answers' => ['reader' => 'Families', 'what_to_avoid' => 'Client names', 'tone_of_voice' => 'warm']]);

        $brief = $this->studio()->fillBrief(new BriefRequest(self::kind(), 'A family garden in Hexham'))->value;
        $request = $this->fake->prompted('brief-filler')[0];

        $this->assertStringContainsString('- `what_to_avoid` (optional): What must not appear?', $request->instructions);
        $this->assertStringNotContainsString('<brief>', $request->instructions);
        $this->assertSame('A garden for a family', $brief->title);
        $this->assertSame(['reader' => 'Families', 'what-to-avoid' => 'Client names', 'tone of voice' => 'warm'], $brief->answers);
    }

    public function test_with_candidates_the_brief_schema_asks_for_examples_from_them(): void
    {
        $schema = Studio::briefSchema(self::kind(), true, [12, 'abc-1', 12]);

        $this->assertSame(['title', 'answers', 'examples'], $schema->schema['required']);
        $this->assertSame(['12', 'abc-1'], $schema->schema['properties']['examples']['items']['enum']);
        $this->assertSame(6, $schema->schema['properties']['examples']['maxItems']);
        $this->assertArrayNotHasKey('examples', Studio::briefSchema(self::kind(), true)->schema['properties'], 'Nothing to choose from.');
    }

    public function test_the_brief_filler_chooses_what_to_model_it_on_when_nothing_is_ticked(): void
    {
        $this->fake->respondStructured('brief-filler', ['title' => 'February jobs', 'answers' => ['reader' => 'Gardeners', 'what_to_avoid' => '', 'tone_of_voice' => ''], 'examples' => ['8', 'nope', '8', '7']]);
        $request = (new BriefRequest(self::kind(), 'A February jobs guide', titles: ['Draft', 'October jobs', 'Seedheads']))
            ->withCandidates([7 => 'Seedheads', 8 => 'October jobs']);

        $brief = $this->studio()->fillBrief($request)->value;
        $sent = $this->fake->prompted('brief-filler')[0];

        $this->assertSame([8, 7], $brief->examples, 'Candidates only, in its order, once each, with their own IDs.');
        $this->assertSame(['7', '8'], $sent->schema?->schema['properties']['examples']['items']['enum']);
        $this->assertStringContainsString("- Draft\n- October jobs [id: 8]\n- Seedheads [id: 7]", $sent->instructions);
        $this->assertStringContainsString('## What to model it on', $sent->instructions);
        $this->assertStringContainsString('In `examples`, the IDs of the entries you chose', $sent->instructions);
        $this->assertStringNotContainsString('{{', $sent->instructions);
        $this->assertStringEndsWith('Your colleague has not chosen what to model it on: choose for them.', $sent->prompt);
    }

    public function test_the_persons_ticks_win_over_the_fillers_choice(): void
    {
        $this->fake->respondStructured('brief-filler', ['title' => 'x', 'answers' => ['reader' => 'x', 'what_to_avoid' => '', 'tone_of_voice' => ''], 'examples' => ['8']]);
        $request = (new BriefRequest(self::kind(), 'x', examples: [7]))->withCandidates([['id' => 7, 'title' => 'A'], ['id' => 8, 'title' => 'B']]);

        $this->assertSame([7], $this->studio()->fillBrief($request)->value->examples);
        $this->assertStringEndsWith('Your colleague has already chosen what to model it on: leave the examples empty.', $this->fake->prompted('brief-filler')[0]->prompt);
    }

    public function test_without_structured_output_the_choice_is_read_from_its_examples_block(): void
    {
        $this->fake->withoutStructuredOutput()->respond('brief-filler', "<title>x</title>\n<brief>\nreader: x\n</brief>\n<examples>\n- b-2\n- \"a-1\", zzz\n</examples>");
        $request = (new BriefRequest(self::kind(), 'x'))->withCandidates(['a-1' => 'A', 'b-2' => 'B']);

        $brief = $this->studio()->fillBrief($request)->value;
        $sent = $this->fake->prompted('brief-filler')[0];

        $this->assertSame(['b-2', 'a-1'], $brief->examples);
        $this->assertStringContainsString('then an `<examples>` block, and nothing else', $sent->instructions);
        $this->assertStringContainsString('<examples>123, 456</examples>', $sent->instructions);
    }

    public function test_without_candidates_the_prompt_says_nothing_of_choosing(): void
    {
        $this->fake->withoutStructuredOutput()->respond('brief-filler', "<brief>\nreader: x\n</brief>");

        $this->studio()->fillBrief(new BriefRequest(self::kind(), 'x', titles: ['A']));
        $sent = $this->fake->prompted('brief-filler')[0];

        $this->assertStringNotContainsString('model it on', $sent->instructions);
        $this->assertStringNotContainsString('examples', $sent->instructions);
        $this->assertStringContainsString('inside a `<brief>` block, and nothing else.', $sent->instructions);
        $this->assertSame("What your colleague said:\nx", $sent->prompt);
    }

    public function test_an_unreadable_brief_is_asked_for_once_more(): void
    {
        $this->fake->respond('brief-writer', 'Here is the brief, I hope it helps.', (string) json_encode(['answers' => ['reader' => 'Families', 'what_to_avoid' => '', 'tone_of_voice' => 'plain']]));

        $answers = $this->studio()->draftBrief(self::kind(), 'A family garden')->value;
        $sent = $this->fake->prompted('brief-writer');

        $this->assertCount(2, $sent);
        $this->assertStringEndsWith("Your last answer to this couldn't be read: there was no <brief> block. Answer again, in full, exactly in the format asked.", $sent[1]->prompt);
        $this->assertSame(['reader' => 'Families', 'what-to-avoid' => '', 'tone of voice' => 'plain'], $answers);
    }

    public function test_without_structured_output_the_brief_is_read_from_its_yaml_by_handle(): void
    {
        $this->fake->withoutStructuredOutput()->respond('brief-writer', "<brief>\nreader: Families\nwhat-to-avoid: Names\n</brief>");

        $answers = $this->studio()->draftBrief(self::kind(), 'A family garden')->value;

        $this->assertStringContainsString('- `what-to-avoid` (optional)', $this->fake->prompted('brief-writer')[0]->instructions);
        $this->assertSame(['reader' => 'Families', 'what-to-avoid' => 'Names', 'tone of voice' => ''], $answers);
    }

    public function test_the_reworder_reads_two_versions_from_json(): void
    {
        $this->fake->respondStructured('reworder', ['versions' => ['Our winter visits', 'Winter garden visits']]);

        $versions = $this->studio()->reword(new RewordRequest('Winter care visits', 'Winter care visits.', 'Dated.', ['Winter care visits']))->value;
        $request = $this->fake->prompted('reworder')[0];

        $this->assertSame(['Our winter visits', 'Winter garden visits'], $versions);
        $this->assertSame('versions', $request->schema?->name);
        $this->assertStringNotContainsString('<version>', $request->instructions);
    }

    public function test_the_reworder_without_a_version_is_asked_once_more(): void
    {
        $this->fake->withoutStructuredOutput()->respond('reworder', 'Sorry.', "<versions>\n<version>Our winter visits</version>\n</versions>");

        $versions = $this->studio()->reword(new RewordRequest('Winter care visits', 'Winter care visits.', 'Dated.', []))->value;

        $this->assertSame(['Our winter visits'], $versions);
        $this->assertStringContainsString('<version>', $this->fake->prompted('reworder')[0]->instructions);
        $this->assertCount(2, $this->fake->prompted('reworder'));
    }

    public function test_the_gap_filler_reads_its_result_from_json(): void
    {
        $this->fake->respondStructured('gap-filler', ['result' => 'A family garden in Hexham, planted for year-round colour.']);

        $result = $this->studio()->fillGap(GapRequest::summary('Summary', 'A family garden in Hexham, planted for year-round colour.'))->value;
        $request = $this->fake->prompted('gap-filler')[0];

        $this->assertSame('A family garden in Hexham, planted for year-round colour.', $result);
        $this->assertSame('result', $request->schema?->name);
        $this->assertStringNotContainsString('<result>', $request->instructions);
        $this->assertStringContainsString('as the `result` of the JSON', $request->instructions);
    }

    public function test_every_request_with_a_schema_keeps_its_instructions_the_same_across_calls(): void
    {
        $this->fake->respondStructured('reworder', ['versions' => ['One', 'Two']]);
        $studio = $this->studio();

        $studio->reword(new RewordRequest('Winter care visits', 'Winter care visits.', 'Dated.', []));
        $studio->reword(new RewordRequest('Spring visits', 'Spring visits.', 'Dated.', []));

        [$first, $second] = $this->fake->prompted('reworder');
        $this->assertSame($first->instructions, $second->instructions, 'Cached instructions stay byte-identical.');
        $this->assertSame(json_encode(Schemas::anthropic($first->schema)), json_encode(Schemas::anthropic($second->schema)));
        $this->assertInstanceOf(TextRequest::class, $first);
    }
}
