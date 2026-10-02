<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ImagerySample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Question;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use NineteenNinetyFour\Ghostwriter\Core\Studio\VoiceSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;

/**
 * The voice, imagery, brief and writing jobs: the request each sends and
 * how its reply is read.
 */
class WritingJobsTest extends StudioTestCase
{
    public function test_the_voice_analyst_reads_numbered_escaped_samples(): void
    {
        $this->fake->respond('voice-analyst', self::reply("\n# Voice\n\nPlain words.\n", 100, 200));

        $guide = $this->studio()->analyseVoice([
            new VoiceSample('Fish & "chips"', 'articles', 'We fry.'),
            new VoiceSample('About', 'pages', 'We are <b>bold</b>.'),
        ]);

        $request = $this->sent('voice-analyst');
        $this->assertSame($this->library()->get('voice-analyst'), $request->instructions);
        $this->assertSame(
            "Here are 2 samples of published writing from the website.\n\n"
            ."<sample number=\"1\" collection=\"articles\" title=\"Fish &amp; &quot;chips&quot;\">\nWe fry.\n</sample>\n\n"
            ."<sample number=\"2\" collection=\"pages\" title=\"About\">\nWe are <b>bold</b>.\n</sample>\n\n"
            .'Write the tone of voice guide.',
            $request->prompt,
        );
        $this->assertSame([], $request->history);
        $this->assertSame([], $request->images);
        $this->assertNull($request->maxTokens);
        $this->assertSame(16000, $request->resolvedMaxTokens());
        $this->assertNull($request->resolvedEffort());

        $this->assertSame('', $guide->reply);
        $this->assertSame("# Voice\n\nPlain words.", $guide->document);
        $this->assertSame([100, 200], [$guide->inputTokens, $guide->outputTokens]);
    }

    public function test_the_voice_editor_gets_the_guide_the_request_and_the_conversation(): void
    {
        $this->fake->respond('voice-editor', self::reply("<reply>Shorter now.</reply>\n<document>\n# Voice\n</document>", 5, 6));

        $guide = $this->studio()->refineVoice('# Voice, long', [['role' => 'user', 'content' => 'Hi', 'at' => '2026-10-02'], new Message('assistant', 'Hello')], 'Make it shorter');

        $request = $this->sent('voice-editor');
        $this->assertSame("<current_guide>\n# Voice, long\n</current_guide>\n\nRequest: Make it shorter", $request->prompt);
        $this->assertEquals([new Message('user', 'Hi'), new Message('assistant', 'Hello')], $request->history);
        $this->assertSame($this->library()->get('voice-editor'), $request->instructions);

        $this->assertSame('Shorter now.', $guide->reply);
        $this->assertSame('# Voice', $guide->document);
        $this->assertSame([5, 6], [$guide->inputTokens, $guide->outputTokens]);
    }

    public function test_the_imagery_analyst_sees_the_images_in_order(): void
    {
        $this->fake->respond('imagery-analyst', self::reply("<document>\nWarm close-ups.\n</document>", 7, 8));
        $one = new Image('one', 'image/jpeg');
        $two = new Image('two', 'image/png');

        $style = $this->studio(Vocabulary::filament())->analyseImagery('Projects', [
            new ImagerySample('Hero image', 'Harbour wall', $one),
            new ImagerySample('Gallery', 'Mill "Lane"', $two),
        ]);

        $request = $this->sent('imagery-analyst');
        $this->assertSame("Section: Projects\n\nThe attached images, in order:\n1. Hero image, on \"Harbour wall\"\n2. Gallery, on \"Mill \"Lane\"\"", $request->prompt);
        $this->assertSame([$one, $two], $request->images);
        $this->assertSame(6000, $request->resolvedMaxTokens());
        $this->assertSame('Warm close-ups.', $style->value);
        $this->assertSame([7, 8], [$style->usage->input, $style->usage->output]);
    }

    public function test_an_imagery_reply_without_a_document_is_kept_whole(): void
    {
        $this->fake->respond('imagery-analyst', "  Muted colours.\n");

        $this->assertSame('Muted colours.', $this->studio()->analyseImagery('News', [])->value);
    }

    public function test_the_brief_writer_lists_the_questions_and_the_existing_titles(): void
    {
        $this->fake->respond('brief-writer', self::reply("<brief>\nwhat: A new kiln\nwhen: 2026\nwho: [a, list]\nextra: ignored\n</brief>", 3, 4));

        $brief = $this->studio()->draftBrief($this->kind(), 'Kiln opening', "  Opens in May.  \n", ['Old kiln', 'Mill']);

        $request = $this->sent('brief-writer');
        $this->assertSame("Working title: Kiln opening\n\nNotes:\nOpens in May.", $request->prompt);
        $this->assertSame(strtr($this->library()->get('brief-writer'), [
            '{{ type_title }}' => 'Project',
            '{{ type_description }}' => 'One project, start to finish.',
            '{{ type_guidance }}' => 'Lead with the outcome.',
            '{{ questions }}' => "- `what` (required): What was made? Be specific.\n- `when` (optional): When? One of: spring, autumn.\n- `who` (optional): Who for?",
            '{{ entries }}' => "- Old kiln\n- Mill",
        ]), $request->instructions);
        $this->assertSame(6000, $request->resolvedMaxTokens());

        $this->assertSame(['what' => 'A new kiln', 'when' => '2026', 'who' => ''], $brief->value);
        $this->assertSame(3, $brief->usage->input);
    }

    public function test_a_brief_with_no_notes_or_entries_says_so(): void
    {
        $this->fake->respond('brief-writer', "<brief>\nwhat: x\n</brief>");

        $this->studio()->draftBrief($this->kind(), 'Kiln', ' ');

        $request = $this->sent('brief-writer');
        $this->assertStringEndsWith("Notes:\n(none)", $request->prompt);
        $this->assertStringContainsString('None yet.', $request->instructions);
    }

    public function test_a_brief_that_cannot_be_read_says_to_fill_it_in_by_hand(): void
    {
        foreach (['No brief here.', "<brief>\nwhat: [unclosed\n</brief>"] as $answer) {
            $this->fake->reset()->respond('brief-writer', $answer);

            try {
                $this->studio()->draftBrief($this->kind(), 'Kiln');
                $this->fail('An unreadable brief was accepted.');
            } catch (UnreadableReply $exception) {
                $this->assertSame('Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.', $exception->getMessage());
                $this->assertSame('brief-writer', $exception->agent);
            }
        }
    }

    public function test_the_writer_gets_the_draft_before_the_latest_message_and_the_rest_as_history(): void
    {
        $this->fake->respond('writer', self::reply("<reply>Done.</reply>\n<draft>\ntitle: Kiln\n</draft>", 11, 12));

        $conversation = new Conversation([
            ['role' => 'user', 'content' => 'Here is the brief.'],
            ['role' => 'assistant', 'content' => 'A first go.'],
            ['role' => 'user', 'content' => 'Shorter, please.'],
        ], "title: Kiln, long\n");

        $turn = $this->studio()->write($conversation, $this->context());

        $request = $this->sent('writer');
        $this->assertSame("<current_draft>\ntitle: Kiln, long\n\n</current_draft>\n\nShorter, please.", $request->prompt);
        $this->assertEquals([new Message('user', 'Here is the brief.'), new Message('assistant', 'A first go.')], $request->history);
        $this->assertSame($this->studio()->writerInstructions($this->context()), $request->instructions);
        $this->assertSame(16000, $request->resolvedMaxTokens());

        $this->assertSame('Done.', $turn->reply);
        $this->assertSame('title: Kiln', $turn->document);
        $this->assertSame([11, 12], [$turn->inputTokens, $turn->outputTokens]);
    }

    public function test_the_first_turn_is_the_brief_alone(): void
    {
        $this->fake->respond('writer', '<draft>title: x</draft>');

        $this->studio()->write(new Conversation([['role' => 'user', 'content' => 'The brief.']]), $this->context());

        $this->assertSame('The brief.', $this->sent('writer')->prompt);
        $this->assertSame([], $this->sent('writer')->history);
    }

    public function test_the_writer_instructions_are_filled_from_the_kind_the_voice_and_the_layout(): void
    {
        $context = $this->context(voice: "  Warm.  \n");

        $this->assertSame(strtr($this->library()->get('writer'), [
            '{{ voice }}' => 'Warm.',
            '{{ type_title }}' => 'Project',
            '{{ type_description }}' => 'One project, start to finish.',
            '{{ type_guidance }}' => 'Lead with the outcome.',
            '{{ type_checklist }}' => "- Names the client.\n- Has a figure.",
            '{{ fields }}' => "- title (text)\n- body (rich text)",
            '{{ examples }}' => "<example number=\"1\">\ntitle: Mill\nbody: |-\n  Line one.\n  Line two.\n</example>\n\n<example number=\"2\">\ntitle: Barn\n</example>",
            '{{ images }}' => 'Leave image fields out.',
        ]), $this->studio()->writerInstructions($context));
    }

    public function test_without_a_voice_checklist_or_examples_the_writer_is_told_what_to_follow(): void
    {
        $context = new WriterContext(new ContentKind('note', 'Note'), '', new Layout('- title (text)'), 'No images.');
        $instructions = $this->studio()->writerInstructions($context);

        $this->assertStringContainsString('No guide has been written yet. Write plainly and specifically, and match the existing entries shown below.', $instructions);
        $this->assertStringContainsString('- It reads like the existing entries.', $instructions);
        $this->assertStringContainsString('Nothing has been published here yet, so there are no examples. Follow the fields and the guidance.', $instructions);
        $this->assertStringNotContainsString('{{', $instructions);
    }

    public function test_examples_are_written_to_the_depth_each_addon_used_and_cut_short_when_long(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => 'deep']]]];
        $layout = new Layout('fields', [$deep, ['body' => str_repeat('word ', 2000)]]);
        $kind = new ContentKind('k', 'K');

        $statamic = $this->studio(options: StudioOptions::statamic())->writerInstructions(new WriterContext($kind, '', $layout, ''));
        $shallow = $this->studio(options: new StudioOptions(exampleDepth: 2))->writerInstructions(new WriterContext($kind, '', $layout, ''));

        $this->assertStringContainsString("a:\n  b:\n    c:\n      d: deep", $statamic);
        $this->assertStringContainsString("a:\n  b: { c: { d: deep } }", $shallow);
        $this->assertStringContainsString("\n# (example cut short)\n</example>", $statamic);
        $this->assertSame(Studio::EXAMPLE_LIMIT, mb_strlen(explode("\n# (example cut short)", explode("<example number=\"2\">\n", $statamic)[1])[0]));
    }

    public function test_the_brief_opens_a_session_in_the_vocabulary(): void
    {
        $answers = ['what' => '  A kiln ', 'when' => '', 'who' => ['not', 'text']];

        $this->assertSame(
            "Here is the brief for a new entry: Project.\n\n**What was made?**\nA kiln\n\n**When?**\n(not answered)\n\n**Who for?**\n(not answered)",
            $this->studio()->brief($this->kind(), $answers),
        );
        $this->assertStringStartsWith('Here is the brief for a new record: Project.', $this->studio(Vocabulary::filament())->brief($this->kind(), $answers));
    }

    public function test_a_photo_search_is_a_few_plain_words_from_the_title_and_summary(): void
    {
        $this->fake->respond('photo-query', self::reply("Harbour, boats!\n", 2, 3));

        $query = $this->studio()->photoQuery('Our new harbour', 'Boats come back');

        $request = $this->sent('photo-query');
        $this->assertSame("Title: Our new harbour\nSummary: Boats come back", $request->prompt);
        $this->assertSame($this->library()->get('photo-query'), $request->instructions);
        $this->assertSame(['low', 2000], [$request->resolvedEffort()?->value, $request->resolvedMaxTokens()]);
        $this->assertSame('harbour  boats', $query->value);
        $this->assertSame(3, $query->usage->output);
    }

    public function test_a_photo_search_falls_back_to_the_title(): void
    {
        $this->fake->respond('photo-query', 'one two three four five six seven', '...', 'boats');
        $studio = $this->studio();

        $this->assertSame('Title', $studio->photoQuery('Title')->value);
        $this->assertSame('Title', $studio->photoQuery('Title')->value);

        $this->fake->reset()->failWith('photo-query', new Overloaded('Busy.', 'fake'));
        $this->assertSame('Title', $studio->photoQuery('Title')->value);
        $this->assertStringContainsString('choosing a photo search failed: Busy.', $this->logs[0]['message']);

        // With no key, nothing is asked.
        $providers = new Providers(new ArrayCredentials([]), new MockHttpClient, new StaticProviderSettings);
        $this->assertFalse((new Studio($providers, $this->library()))->configured());
        $this->assertSame('Title', (new Studio($providers, $this->library()))->photoQuery('Title')->value);
    }

    public function test_the_summary_for_a_photo_search_comes_from_the_draft(): void
    {
        $this->assertSame('Boats come back', Studio::summaryOf("title: Harbour\nsummary: \"Boats come back\"\nbody: x"));
        $this->assertSame('', Studio::summaryOf("title: Harbour\n"));
        $this->assertSame('', Studio::summaryOf(null));
    }

    public function test_a_registry_without_a_key_throws_not_configured_when_asked(): void
    {
        $providers = new Providers(new ArrayCredentials([]), new MockHttpClient, new StaticProviderSettings);

        $this->expectException(NotConfigured::class);

        (new Studio($providers, $this->library()))->analyseVoice([]);
    }

    public function test_a_registry_with_a_fake_is_used(): void
    {
        $providers = new Providers(new ArrayCredentials([]), new MockHttpClient, new StaticProviderSettings);
        $fake = $providers->fake(new FakeProvider);
        $fake->respond('voice-analyst', '# Voice');

        $studio = new Studio($providers, $this->library());

        $this->assertTrue($studio->configured());
        $this->assertSame('# Voice', $studio->analyseVoice([])->document);
    }

    private function kind(): ContentKind
    {
        return ContentKind::fromArray('project', [
            'title' => 'Project',
            'description' => 'One project, start to finish.',
            'guidance' => 'Lead with the outcome.',
            'checklist' => ['Names the client.', 'Has a figure.'],
            'questions' => [
                ['handle' => 'what', 'label' => 'What was made?', 'instructions' => 'Be specific.', 'required' => true],
                ['handle' => 'when', 'label' => 'When?', 'options' => ['spring' => 'Spring', 'autumn' => 'Autumn']],
                new Question('who', 'Who for?'),
            ],
        ]);
    }

    private function context(string $voice = 'Warm.'): WriterContext
    {
        return new WriterContext(
            $this->kind(),
            $voice,
            Layout::fromPattern("- title (text)\n- body (rich text)", ['entries' => 2, 'examples' => [['title' => 'Mill', 'body' => "Line one.\nLine two."], ['title' => 'Barn']]]),
            'Leave image fields out.',
        );
    }
}
