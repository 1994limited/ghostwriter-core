<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Question;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;

/**
 * F8: a reply that can't be read is logged as the problem only, unless the
 * addon turns on logReplies. Prompts, instructions and keys never are.
 */
class UnreadableLoggingTest extends StudioTestCase
{
    private const SECRET = 'The reply says PRIVATE-CLIENT-NAME';

    public function test_by_default_only_the_problem_is_logged(): void
    {
        $this->runEveryUnreadableJob($this->studio(options: StudioOptions::craft()));

        $this->assertSame([
            ['warning', 'Ghostwriter: the type analysis for articles could not be read (there was no <type> block); asking again.'],
            ['warning', 'Ghostwriter: the type analysis for articles could not be read again (the YAML did not parse at line 1).'],
            ['warning', 'Ghostwriter: the kinds for articles could not be read (there was no <kinds> block).'],
            ['warning', 'Ghostwriter: the kinds for articles could not be read (the YAML did not parse at line 1).'],
            ['warning', "Ghostwriter: the planner's ideas could not be read (there was no <ideas> block)."],
            ['warning', "Ghostwriter: the planner's ideas could not be read (the YAML did not parse at line 1)."],
            ['warning', 'Ghostwriter: the brief could not be read (there was no <brief> block).'],
        ], array_map(fn (array $log) => [$log['level'], $log['message']], $this->logs));

        $this->assertSame(['agent' => 'type-analyst', 'group' => 'articles'], $this->logs[0]['context']);
        $this->assertStringNotContainsString('PRIVATE', $this->logged());
        $this->assertNothingSecretLogged();
    }

    public function test_with_log_replies_on_the_whole_reply_is_in_the_context(): void
    {
        $this->runEveryUnreadableJob($this->studio(options: StudioOptions::craft(logReplies: true)));

        $this->assertCount(7, $this->logs);

        foreach ($this->logs as $log) {
            $this->assertStringContainsString('PRIVATE-CLIENT-NAME', $log['context']['reply']);
            $this->assertStringNotContainsString('PRIVATE', $log['message']);
        }

        $this->assertNothingSecretLogged();
    }

    public function test_statamic_logs_a_reply_with_nothing_to_add_as_info_and_only_with_log_replies_quotes_it(): void
    {
        $survey = new KindSurvey('Articles', 'articles', [new KindSample('a', 'A'), new KindSample('b', 'B')]);
        $this->fake->respond('kind-finder', self::SECRET);

        $this->studio(options: StudioOptions::statamic())->suggestKinds($survey);
        $this->studio(options: StudioOptions::statamic()->withLogReplies())->suggestKinds($survey);

        $this->assertSame(['info', 'Ghostwriter: no kinds suggested for articles.'], [$this->logs[0]['level'], $this->logs[0]['message']]);
        $this->assertArrayNotHasKey('reply', $this->logs[0]['context']);
        $this->assertSame(self::SECRET, $this->logs[1]['context']['reply']);
    }

    private function runEveryUnreadableJob(Studio $studio): void
    {
        $this->fake->respond('type-analyst', self::SECRET, "<type>\n".self::SECRET.": [\n</type>");
        $this->fake->respond('kind-finder', self::SECRET, "<kinds>\n".self::SECRET.": [\n</kinds>");
        $this->fake->respond('planner', self::SECRET, "<ideas>\n".self::SECRET.": [\n</ideas>");
        $this->fake->respond('brief-writer', self::SECRET);

        $survey = new KindSurvey('Articles', 'articles', [new KindSample('a', 'A'), new KindSample('b', 'B')]);
        $plan = new PlanContext([new PlanGroup('Articles', 'articles')], voice: 'VOICE-GUIDE-TEXT');
        $jobs = [
            fn () => $studio->analyseType(new TypeSurvey('Articles', 'articles', new Layout('FIELDS-TEXT'))),
            fn () => $studio->suggestKinds($survey),
            fn () => $studio->suggestKinds($survey),
            fn () => $studio->suggestIdeas($plan),
            fn () => $studio->suggestIdeas($plan),
            fn () => $studio->draftBrief(new ContentKind('k', 'K', questions: [new Question('q', 'Q')]), 'Title'),
        ];

        foreach ($jobs as $job) {
            try {
                $job();
                $this->fail('An unreadable reply was accepted.');
            } catch (UnreadableReply) {
                // Expected.
            }
        }
    }

    private function assertNothingSecretLogged(): void
    {
        foreach (['FIELDS-TEXT', 'VOICE-GUIDE-TEXT', 'You are', 'Write the type.'] as $never) {
            $this->assertStringNotContainsString($never, $this->logged());
        }
    }
}
