<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;

/**
 * analyseType, ported from the addons' "unreadable analysis" and "code
 * fence or fixed on the second try" tests.
 */
class TypeAnalysisTest extends StudioTestCase
{
    private const TYPE = "<type>\ntitle: Project\ndescription: One project.\nquestions:\n  - handle: what\n    label: What?\n</type>";

    public function test_the_analyst_is_shown_the_group_the_fields_and_the_examples(): void
    {
        $this->fake->respond('type-analyst', self::reply(self::TYPE, 40, 50));

        $result = $this->studio()->analyseType($this->survey(title: 'Case study', chosen: true));

        $request = $this->sent('type-analyst');
        $this->assertSame($this->library()->get('type-analyst'), $request->instructions);
        $this->assertSame(
            "Section: Articles (3 entries studied)\n\n"
            ."The editors call this kind of content \"Case study\". Use that as the title.\n\n"
            ."The entries below were chosen by an editor as the model for this kind of content. Other entries in the section may look different; describe only these.\n\n"
            ."## The fields\n\n- title (text)\n- body (rich text)\n\n"
            ."## Existing entries\n\n<example number=\"1\">\ntitle: One\n</example>\n\n"
            .'Write the type.',
            $request->prompt,
        );
        $this->assertSame([], $request->history);
        $this->assertSame(16000, $request->resolvedMaxTokens());

        $this->assertSame('Project', $result->value['title']);
        $this->assertSame([['handle' => 'what', 'label' => 'What?']], $result->value['questions']);
        $this->assertSame([40, 50], [$result->usage->input, $result->usage->output]);
    }

    public function test_without_a_title_or_chosen_examples_those_lines_are_left_out(): void
    {
        $this->fake->respond('type-analyst', self::TYPE);

        $this->studio()->analyseType(new TypeSurvey('Articles', 'articles', new Layout('fields', [], 0)));

        $this->assertSame(
            "Section: Articles (0 entries studied)\n\n## The fields\n\nfields\n\n## Existing entries\n\n"
            ."Nothing has been published here yet, so there are no examples. Follow the fields and the guidance.\n\nWrite the type.",
            $this->sent('type-analyst')->prompt,
        );
    }

    public function test_an_unreadable_analysis_is_asked_for_again_with_the_reason_and_the_conversation(): void
    {
        $this->fake->respond('type-analyst', self::reply('Sorry, I cannot help with that.', 10, 20), self::reply(self::TYPE, 1, 2));

        $result = $this->studio()->analyseType($this->survey());

        $first = $this->sent('type-analyst');
        $again = $this->sent('type-analyst', 1);

        $this->assertSame('Your answer could not be read: there was no <type> block. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.', $again->prompt);
        $this->assertEquals([new Message('user', $first->prompt), new Message('assistant', 'Sorry, I cannot help with that.')], $again->history);
        $this->assertSame($first->instructions, $again->instructions);

        // Both calls are counted.
        $this->assertSame([11, 22], [$result->usage->input, $result->usage->output]);
        $this->assertSame('Project', $result->value['title']);
    }

    public function test_a_type_in_a_code_fence_or_fixed_on_the_second_try_is_read(): void
    {
        $this->fake->respond('type-analyst',
            "<type>\n```yaml\ntitle: Fenced\nquestions: not a list\n```\n</type>",
            "<type>\n```yaml\ntitle: Fenced\nquestions:\n  - handle: what\n    label: What?\n```\n</type>",
        );

        $this->assertSame('Fenced', $this->studio()->analyseType($this->survey())->value['title']);
        $this->assertStringContainsString('could not be read: it had no questions.', $this->sent('type-analyst', 1)->prompt);
    }

    public function test_yaml_that_does_not_parse_is_explained_to_the_model_in_full(): void
    {
        $this->fake->respond('type-analyst', "<type>\ntitle: [unclosed\nquestions: x\n</type>", self::TYPE);

        $this->studio()->analyseType($this->survey());

        $this->assertMatchesRegularExpression('/^Your answer could not be read: the YAML did not parse \(.+\)\. Reply again/s', $this->sent('type-analyst', 1)->prompt);
    }

    public function test_an_analysis_unreadable_twice_fails_with_a_message_for_the_person(): void
    {
        $this->fake->respond('type-analyst', 'Sorry.', 'Still no.');

        try {
            $this->studio()->analyseType($this->survey());
            $this->fail('An unreadable analysis was accepted.');
        } catch (UnreadableReply $exception) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
            $this->assertSame('The analysis came back in a form that could not be read. Try again.', $exception->getMessage());
            $this->assertSame('there was no <type> block', $exception->problem);
            $this->assertSame('type-analyst', $exception->agent);
        }

        $this->assertCount(2, $this->fake->prompted('type-analyst'));
    }

    private function survey(?string $title = null, bool $chosen = false): TypeSurvey
    {
        return new TypeSurvey('Articles', 'articles', Layout::fromPattern("- title (text)\n- body (rich text)", ['entries' => 3, 'examples' => [['title' => 'One']]]), $title, $chosen);
    }
}
