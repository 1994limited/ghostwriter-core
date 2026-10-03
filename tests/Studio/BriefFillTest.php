<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefCheck;
use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;

/**
 * The brief, filled in for the conversation's brief card (1.6): one call,
 * a working title, an answer for every question, the examples as chosen,
 * nothing about the organisation the person didn't say, and "Try again".
 */
final class BriefFillTest extends StudioTestCase
{
    private function kind(): ContentKind
    {
        return ContentKind::fromArray('project', [
            'title' => 'Project write-up',
            'description' => 'One project, from the brief to the result.',
            'guidance' => 'Lead with the outcome. Keep it under 800 words.',
            'questions' => [
                ['handle' => 'client', 'label' => 'Who was it for?', 'required' => true],
                ['handle' => 'scope', 'label' => 'What was the work?', 'options' => ['site' => 'A website', 'app' => 'An app']],
                ['handle' => 'result', 'label' => 'What changed?'],
                ['handle' => 'shape', 'label' => 'Anything about its length?'],
            ],
        ]);
    }

    public function test_the_brief_is_filled_from_the_quick_details_in_one_call(): void
    {
        $this->fake->respond('brief-filler', self::reply("<title>The new kiln opens</title>\n<brief>\nclient: The Harbour Trust\nscope: site\nresult: \"[Add: what changed for the trust]\"\nshape: About 600 words, in three sections.\n</brief>", 7, 9));

        $result = $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), '  Kiln opening. Opens in May, for the Harbour Trust.  ', ['Old kiln', 'Mill'], ['a', 'b']));

        $this->assertCount(1, $this->fake->requests());
        $request = $this->sent('brief-filler');
        $this->assertSame("What your colleague said:\nKiln opening. Opens in May, for the Harbour Trust.", $request->prompt);
        $this->assertStringContainsString("- `client` (required): Who was it for?\n- `scope` (optional): What was the work? One of: site, app.", $request->instructions);
        $this->assertStringContainsString("## Other entries in this part of the site\n\n- Old kiln\n- Mill", $request->instructions);
        $this->assertStringContainsString('Never invent facts about the organisation.', $request->instructions);
        $this->assertStringNotContainsString('guess', $request->instructions);
        $this->assertSame(Agents::MAX_TOKENS['brief-filler'], $request->resolvedMaxTokens());

        $brief = $result->value;
        $this->assertSame('The new kiln opens', $brief->title);
        $this->assertSame(['client' => 'The Harbour Trust', 'scope' => 'site', 'result' => '[Add: what changed for the trust]', 'shape' => 'About 600 words, in three sections.'], $brief->answers);
        $this->assertSame(['a', 'b'], $brief->examples);
        $this->assertSame(1, $brief->attempt);
        $this->assertSame(['result'], $brief->open());
        $this->assertSame(7, $result->usage->input);
        $this->assertSame(9, $result->usage->output);
    }

    public function test_the_vocabulary_names_the_records(): void
    {
        $this->fake->respond('brief-filler', "<brief>\nclient: x\n</brief>");

        $this->studio(Vocabulary::filament())->fillBrief(BriefRequest::fromDetails($this->kind(), 'x'));

        $this->assertStringContainsString('## Other records in this part of the', $this->sent('brief-filler')->instructions);
        $this->assertStringContainsString('None yet.', $this->sent('brief-filler')->instructions);
    }

    public function test_figures_and_quotes_the_person_did_not_give_are_left_for_them(): void
    {
        $this->fake->respond('brief-filler', "<title>Kiln</title>\n<brief>\nclient: The trust, who have worked with us since 2019\nresult: |\n  Visitors rose 40% and bookings by £12,000. They said \"it changed everything for us\".\n  It opens on 14 May.\nshape: Under 800 words, 3 short sections.\n</brief>");

        $result = $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'Kiln opening on 14 May for the trust.'));

        $this->assertSame('The trust, who have worked with us since '.BriefCheck::FIGURE, $result->value->answers['client']);
        $this->assertSame('Visitors rose '.BriefCheck::FIGURE.' and bookings by '.BriefCheck::FIGURE.'. They said '.BriefCheck::QUOTE.".\nIt opens on 14 May.", $result->value->answers['result']);
        $this->assertSame('Under 800 words, 3 short sections.', $result->value->answers['shape']);
        $this->assertSame(['client', 'result'], $result->value->open());
        $this->assertStringContainsString('the brief had facts the person did not give, now left for them (client: a figure (2019); result: a quotation; result: a figure (40%); result: a figure (£12,000))', $this->logged());
        $this->assertStringNotContainsString('Visitors rose', $this->logged());
    }

    public function test_square_brackets_are_left_alone(): void
    {
        $this->fake->respond('brief-filler', "<brief>\nclient: \"[Check: could \\\"Mill 2\\\" be evidence here? Say what we did]\"\nresult: \"[Add: the 3 numbers that matter]\"\n</brief>");

        $result = $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'Kiln'));

        $this->assertSame('[Check: could "Mill 2" be evidence here? Say what we did]', $result->value->answers['client']);
        $this->assertSame('[Add: the 3 numbers that matter]', $result->value->answers['result']);
    }

    public function test_set_answers_must_be_one_of_them_and_required_ones_are_never_blank(): void
    {
        $this->fake->respond('brief-filler', "<brief>\nscope: An app\n</brief>");
        $this->assertSame(['client' => '[Add: Who was it for?]', 'scope' => 'app', 'result' => '', 'shape' => ''], $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'x'))->value->answers);

        $this->fake->reset()->respond('brief-filler', "<brief>\nclient: Us\nscope: a brochure\n</brief>");
        $this->assertSame('', $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'x'))->value->answers['scope']);
    }

    public function test_the_title_is_the_ideas_or_else_from_what_was_said(): void
    {
        $this->fake->respond('brief-filler', "<title>Something else</title>\n<brief>\nclient: x\n</brief>");
        $result = $this->studio()->fillBrief(BriefRequest::fromIdea($this->kind(), ' Kiln opening ', "Nothing on the kiln yet.\n\nStart with May."));

        $this->assertSame("Working title: Kiln opening\n\nNotes:\nNothing on the kiln yet.\n\nStart with May.", $this->sent('brief-filler')->prompt);
        $this->assertSame('Kiln opening', $result->value->title);

        $this->fake->reset()->respond('brief-filler', "<brief>\nclient: x\n</brief>");
        $this->assertSame('A kiln for the trust', $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'A kiln for the trust'))->value->title);
    }

    public function test_a_brief_that_cannot_be_read_says_what_to_do(): void
    {
        foreach (['No brief.', "<brief>\nclient: [unclosed\n</brief>"] as $answer) {
            $this->fake->reset()->respond('brief-filler', $answer);

            try {
                $this->studio()->fillBrief(BriefRequest::fromDetails($this->kind(), 'Kiln'));
                $this->fail('An unreadable brief was accepted.');
            } catch (UnreadableReply $exception) {
                $this->assertSame('Ghostwriter could not fill in the brief from that. Try again, or say a little more about it.', $exception->getMessage());
                $this->assertSame('brief-filler', $exception->agent);
            }
        }
    }

    public function test_try_again_keeps_what_the_person_changed_and_redoes_the_rest(): void
    {
        $previous = new Brief('Kiln', ['client' => 'The trust', 'scope' => 'site', 'result' => 'More visitors.', 'shape' => ''], ['a'], 1);
        $request = BriefRequest::fromDetails($this->kind(), 'Kiln opening for the trust')->tryAgain($previous, ['client' => 'The Harbour Trust, since 2019', 'unknown' => 'x'], ['b', 'c'], 'Our new kiln');

        $this->assertSame(['client'], $request->kept);
        $this->assertSame(['b', 'c'], $request->examples);
        $this->assertSame('Our new kiln', $request->title);

        $this->fake->respond('brief-filler', "<brief>\nclient: Someone else\nscope: app\nresult: Up 2019 visits.\n</brief>");
        $result = $this->studio()->fillBrief($request);

        $prompt = $this->sent('brief-filler')->prompt;
        $this->assertStringStartsWith("Working title: Our new kiln\n\nNotes:\nKiln opening for the trust\n\n<previous_brief>\n<title>Our new kiln</title>\nclient: 'The Harbour Trust, since 2019'\n", $prompt);
        $this->assertStringEndsWith('Your colleague asked you to try again. Keep these exactly, your colleague wrote them: `client`. Answer the rest afresh.', $prompt);

        // The person's own answer is theirs, figure and all; a figure they gave may be used.
        $this->assertSame(['client' => 'The Harbour Trust, since 2019', 'scope' => 'app', 'result' => 'Up 2019 visits.', 'shape' => ''], $result->value->answers);
        $this->assertSame(2, $result->value->attempt);
        $this->assertSame('Our new kiln', $result->value->title);
    }

    public function test_a_brief_models_on_six_records_at_most_and_round_trips(): void
    {
        $brief = new Brief('T', ['a' => '[Add: x]'], [1, 2, 2, 3, 4, 5, 6, 7], 2);

        $this->assertSame([1, 2, 3, 4, 5, 6], $brief->examples);
        $this->assertEquals($brief, Brief::fromArray($brief->toArray()));
        $this->assertSame(['a' => 'y'], $brief->with(['a' => ' y ', 'b' => 'no'])->answers);
        $this->assertFalse(BriefCheck::hasBrackets('Tickets are [[ask: price]]'));
        $this->assertTrue(BriefCheck::hasBrackets('[Add: a client]'));
    }
}
