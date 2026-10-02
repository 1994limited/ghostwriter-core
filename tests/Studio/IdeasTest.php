<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanItem;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlannedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;

/**
 * suggestIdeas, ported from the addons' plan tests.
 */
class IdeasTest extends StudioTestCase
{
    public function test_the_planner_is_shown_every_group_the_plan_and_the_voice(): void
    {
        $this->fake->respond('planner', '<ideas>[]</ideas>');

        $this->studio()->suggestIdeas($this->context());

        $request = $this->sent('planner');
        $this->assertSame('What I am looking for this time:  pricing ', $request->prompt);
        $this->assertSame(strtr($this->library()->get('planner'), [
            '{{ count }}' => '6',
            '{{ voice }}' => 'Warm and plain.',
            '{{ sections }}' => "### Articles (`articles`)\n\nKinds of content written here:\n- `project`: Project. One project.\n\nEntries:\n- How we work: We start with a call.\n- Pricing (draft)\n\n"
                ."### Pages (`pages`)\n\nKinds of content written here:\n- none defined; leave `type` out\n\nEntries:\n- none yet",
            '{{ plan }}' => "- Rebuild or refresh (articles, idea)\n- Our team (pages, drafted)",
        ]), $request->instructions);
        $this->assertStringNotContainsString('{{', $request->instructions);
        $this->assertSame(16000, $request->resolvedMaxTokens());
    }

    public function test_filament_words_its_groups_as_resources_and_records(): void
    {
        $this->fake->respond('planner', '<ideas>[]</ideas>');

        $this->studio(Vocabulary::filament(), StudioOptions::filament())->suggestIdeas(new PlanContext([
            new PlanGroup('Posts', 'posts', [], [PlanItem::fromProse('Hello', false, "  First   post\n here. ".str_repeat('y', 200))]),
        ]));

        $request = $this->sent('planner');
        $this->assertSame('Suggest what is missing.', $request->prompt);
        $this->assertStringContainsString("### Posts (`posts`)\n\nKinds of content written here:\n- none defined; leave `type` out\n\nRecords:\n- Hello (not published): First post here. ".str_repeat('y', 160 - 22)."\n", $request->instructions."\n");
        $this->assertStringContainsString('No guide has been written yet. Judge the reader from the records.', $request->instructions);
        $this->assertStringContainsString("## Already on the plan\n\nNothing yet.", $request->instructions);
        $this->assertStringContainsString('Propose up to 8 ideas', $request->instructions);
    }

    public function test_ideas_for_other_groups_already_planned_or_repeated_are_left_out(): void
    {
        $this->fake->respond('planner', self::reply(<<<'YAML'
            <ideas>
            - title: " How to brief a web agency "
              collection: articles
              type: project
              why: Nothing on briefing.
              notes: The angle.
            - title: Rebuild or Refresh
              collection: articles
            - title: Somewhere else
              collection: nowhere
            - title: How to brief a web agency
              collection: pages
            - title: Team page
              section: pages
              type: project
            - title: A resource
              resource: pages
            - title: ""
              collection: pages
            - plain text
            </ideas>
            YAML, 3, 4));

        $result = $this->studio()->suggestIdeas($this->context());

        $this->assertEquals([
            new SuggestedIdea('How to brief a web agency', 'articles', 'project', 'Nothing on briefing.', 'The angle.'),
            new SuggestedIdea('Team page', 'pages', null, '', ''),
            new SuggestedIdea('A resource', 'pages', null, '', ''),
        ], $result->value);
        $this->assertSame(['title' => 'Team page', 'section' => 'pages', 'type' => null, 'why' => '', 'notes' => ''], $result->value[1]->toArray('section'));
        $this->assertSame(4, $result->usage->output);
    }

    public function test_the_vocabulary_group_key_is_read_first(): void
    {
        $this->fake->respond('planner', "<ideas>\n- title: Idea\n  resource: posts\n  collection: wrong\n</ideas>");

        $ideas = $this->studio(Vocabulary::filament())->suggestIdeas(new PlanContext([new PlanGroup('Posts', 'posts')]))->value;

        $this->assertSame('posts', $ideas[0]->group);
        $this->assertSame(['title' => 'Idea', 'resource' => 'posts', 'kind' => null, 'why' => '', 'notes' => ''], $ideas[0]->toArray('resource', 'kind'));
    }

    public function test_ideas_cut_off_twice_are_kept_as_far_as_they_got(): void
    {
        $this->fake->respond('planner', self::cutOff("<ideas>\n- title: How to brief a web agency\n  collection: articles\n- title: Rebuild or re"));

        $ideas = $this->studio()->suggestIdeas($this->context())->value;

        $this->assertSame(['How to brief a web agency'], array_map(fn (SuggestedIdea $idea) => $idea->title, $ideas));
        $this->assertSame([16000, 32000], array_map(fn ($request) => $request->resolvedMaxTokens(), $this->fake->prompted('planner')));
    }

    public function test_a_reply_without_ideas_or_with_ideas_that_do_not_parse_fails(): void
    {
        foreach (['I have no ideas.' => 'Ghostwriter did not come back with any ideas. Try again.', "<ideas>\n- title: [x\n</ideas>" => 'Ghostwriter did not come back with ideas it could read. Try again.'] as $answer => $message) {
            $this->fake->reset()->respond('planner', $answer);

            try {
                $this->studio()->suggestIdeas($this->context());
                $this->fail('An unreadable plan was accepted.');
            } catch (UnreadableReply $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    private function context(): PlanContext
    {
        return new PlanContext(
            [
                new PlanGroup('Articles', 'articles', [new ContentKind('project', 'Project', 'One project.')], [
                    new PlanItem('How we work', true, 'We start with a call.'),
                    new PlanItem('Pricing', false),
                ]),
                new PlanGroup('Pages', 'pages'),
            ],
            [new PlannedIdea('Rebuild or refresh', 'articles', 'idea'), new PlannedIdea('Our team', 'pages', 'drafted')],
            "\n Warm and plain. \n",
            ' pricing ',
            6,
        );
    }
}
