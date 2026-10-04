<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;

/**
 * suggestKinds, ported from the addons' kind suggestion tests.
 */
class KindsTest extends StudioTestCase
{
    public function test_each_entry_is_one_line_with_quoted_ids_for_string_ids(): void
    {
        $this->fake->withoutStructuredOutput(); // The tagged prompt, as the addons sent it.

        $this->fake->respond('kind-finder', '<kinds></kinds>');

        $this->studio(Vocabulary::statamic(), StudioOptions::statamic())->suggestKinds($this->survey());

        $request = $this->sent('kind-finder');
        $this->assertSame(
            "Section: Articles\n\nEntries, newest first:\n"
            ."- id \"a1\" · \"One\" · blueprint: Article · under: Work · built as: hero, long_form, cards · opens: \"A summary line about One that runs on.\"\n"
            ."- id \"b2\" · \"Two\" · built as: hero\n"
            .'- id "c3" · "Three" · opens: "'.str_repeat('x', 220).'"',
            $request->prompt,
        );
        $this->assertSame(strtr(Studio::replyFormat($this->library()->get('kind-finder'), false), [
            '{{ count }}' => '5',
            '{{ taught }}' => '- Article: A general article.',
            '{{ dismissed }}' => "- Press release\n- Event",
        ]), $request->instructions);
        $this->assertSame(8000, $request->resolvedMaxTokens());
    }

    public function test_numeric_ids_are_not_quoted_and_craft_says_entry_type(): void
    {
        $this->fake->withoutStructuredOutput(); // The tagged prompt, as the addons sent it.

        $this->fake->respond('kind-finder', '<kinds></kinds>');

        $this->studio(Vocabulary::craft(), StudioOptions::craft())->suggestKinds(new KindSurvey('News', 'news', [
            new KindSample(12, 'One', variantName: 'Article'),
            new KindSample(15, 'Two'),
        ]));

        $this->assertSame("Section: News\n\nEntries, newest first:\n- id 12 · \"One\" · entry type: Article\n- id 15 · \"Two\"", $this->sent('kind-finder')->prompt);
        $this->assertStringContainsString('Nothing yet.', $this->sent('kind-finder')->instructions);
        $this->assertStringContainsString('[12, 15]', $this->sent('kind-finder')->instructions);
    }

    public function test_with_fewer_than_two_entries_nothing_is_asked(): void
    {
        $result = $this->studio()->suggestKinds(new KindSurvey('News', 'news', [new KindSample('a', 'One')]));

        $this->assertSame([], $result->value);
        $this->fake->assertNothingSent();
    }

    public function test_kinds_taught_turned_down_repeated_or_without_two_real_examples_are_left_out(): void
    {
        $this->fake->respond('kind-finder', self::reply(<<<'YAML'
            <kinds>
            - title: Project write-up
              description: One project told start to finish.
              why: They share the hero and cards.
              examples: ["a1", "b2", "nowhere", "a1"]
            - title: article
              description: Already taught, so left out.
              examples: ["a1", "b2"]
            - title: Press Release
              examples: ["a1", "b2"]
            - title: Lonely
              examples: ["c3"]
            - title: Project write-up
              examples: ["b2", "c3"]
            - title: "   "
              examples: ["b2", "c3"]
            - just a string
            - title: A very long title that goes on and on well past the sixty characters allowed
              examples: ["b2", "c3"]
            </kinds>
            YAML, 7, 9));

        $result = $this->studio()->suggestKinds($this->survey());

        $this->assertEquals([
            new SuggestedKind('Project write-up', 'One project told start to finish.', 'They share the hero and cards.', ['a1', 'b2'], null),
            new SuggestedKind('A very long title that goes on and on well past the sixty ch', '', '', ['b2', 'c3'], 'page'),
        ], $result->value);
        $this->assertSame([7, 9], [$result->usage->input, $result->usage->output]);
    }

    public function test_a_kind_whose_examples_share_a_blueprint_gets_it(): void
    {
        $this->fake->respond('kind-finder', "<kinds>\n- title: Case\n  examples: [b2, c3]\n</kinds>");

        $kind = $this->studio()->suggestKinds($this->survey())->value[0];

        $this->assertSame('page', $kind->variant);
        $this->assertSame(['title' => 'Case', 'description' => '', 'why' => '', 'examples' => ['b2', 'c3'], 'blueprint' => 'page'], $kind->toArray('blueprint'));
        $this->assertSame(['title' => 'Case', 'description' => '', 'why' => '', 'examples' => ['b2', 'c3']], $kind->toArray());
    }

    public function test_numeric_ids_are_matched_however_the_model_wrote_them_and_come_back_as_given(): void
    {
        $this->fake->respond('kind-finder', "<kinds>\n- title: News\n  examples: [\"12\", 15, 15.0, 99, 1, 2, 3, 4, 5]\n</kinds>");
        $samples = array_map(fn (int $id) => new KindSample($id, "Entry {$id}", variantHandle: 'news'), [12, 15, 1, 2, 3, 4, 5]);

        $kind = $this->studio(Vocabulary::craft())->suggestKinds(new KindSurvey('News', 'news', $samples))->value[0];

        $this->assertSame([12, 15, 1, 2, 3, 4], $kind->examples);
        $this->assertSame(['entryType' => 'news'], array_slice($kind->toArray('entryType'), 4));
    }

    public function test_an_empty_kinds_block_means_nothing_to_add(): void
    {
        $this->fake->respond('kind-finder', "Everything is taught.\n<kinds>\n</kinds>");

        $this->assertSame([], $this->studio(options: StudioOptions::filament())->suggestKinds($this->survey())->value);
        $this->assertSame('info', $this->logs[0]['level']);
        $this->assertSame('Ghostwriter: no kinds suggested for articles.', $this->logs[0]['message']);
    }

    public function test_no_kinds_block_is_unreadable_unless_the_addon_says_it_means_nothing_to_add(): void
    {
        $this->fake->respond('kind-finder', 'Both entries are already covered by the Article kind, so there is nothing to suggest.');

        // Statamic: its prompt invites it, so it is an answer.
        $this->assertSame([], $this->studio(options: StudioOptions::statamic())->suggestKinds($this->survey())->value);

        // Craft and Filament: unreadable.
        foreach ([StudioOptions::craft(), StudioOptions::filament()] as $options) {
            try {
                $this->studio(options: $options)->suggestKinds($this->survey());
                $this->fail('A reply without kinds was accepted.');
            } catch (UnreadableReply $exception) {
                $this->assertSame('Ghostwriter did not come back with any kinds. Try again.', $exception->getMessage());
                $this->assertSame('there was no <kinds> block', $exception->problem);
            }
        }
    }

    public function test_kinds_that_do_not_parse_are_unreadable(): void
    {
        $this->fake->respond('kind-finder', "<kinds>\n- title: [unclosed\n</kinds>");

        $this->expectException(UnreadableReply::class);
        $this->expectExceptionMessage('Ghostwriter did not come back with kinds it could read. Try again.');

        $this->studio()->suggestKinds($this->survey());
    }

    private function survey(): KindSurvey
    {
        return new KindSurvey('Articles', 'articles', [
            new KindSample('a1', 'One', "A summary line\n  about One   that runs on.", ['hero', 'long_form', 'hero', 'cards', ''], 'Work', 'article', 'Article'),
            new KindSample('b2', 'Two', '   ', ['hero'], variantHandle: 'page'),
            new KindSample('c3', 'Three', str_repeat('x', 300), variantHandle: 'page'),
        ], [new ContentKind('article', 'Article', 'A general article.')], ['Press release', 'Event']);
    }
}
