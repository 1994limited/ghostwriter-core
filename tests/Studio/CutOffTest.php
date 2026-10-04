<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The cut-off policy (core-ai-design §6.6), once for every job. Ported from
 * the addons' "cut off once / twice" tests.
 */
class CutOffTest extends StudioTestCase
{
    public function test_a_draft_cut_off_once_is_asked_for_again_with_more_room(): void
    {
        $this->fake->respond('writer', self::cutOff("<reply>Here.</reply>\n<draft>\ntitle: Half", 100, 16000), self::reply("<reply>Here.</reply>\n<draft>\ntitle: Whole\n</draft>", 100, 20000));

        $turn = $this->studio()->write(new Conversation([['role' => 'user', 'content' => 'Brief.']]), $this->writer());

        $this->assertSame('title: Whole', $turn->document);
        $this->assertSame([16000, 32000], $this->limits('writer'));
        $this->assertSame([200, 36000], [$turn->inputTokens, $turn->outputTokens]);

        // Everything but the limit is the same request.
        [$first, $second] = $this->fake->prompted('writer');
        $this->assertSame([$first->instructions, $first->prompt, $first->history], [$second->instructions, $second->prompt, $second->history]);

        $this->assertSame('warning', $this->logs[0]['level']);
        $this->assertSame('Ghostwriter: the writer reply ran out of room at 16000 tokens; asking again with 32000.', $this->logs[0]['message']);
        $this->assertSame(['agent' => 'writer', 'provider' => 'fake', 'model' => 'fake-model'], $this->logs[0]['context']);
    }

    /**
     * @return iterable<string, array{string, string, StudioOptions}>
     */
    public static function wholeAgents(): iterable
    {
        foreach (StudioOptions::WHOLE as $agent) {
            yield "{$agent}, core's message" => [$agent, StudioOptions::CUT_OFF_MESSAGES[$agent], new StudioOptions];
        }

        yield 'writer, Statamic' => ['writer', 'The draft was longer than Ghostwriter allows and was cut off. Try asking for a shorter piece.', StudioOptions::statamic()];
        yield 'voice-analyst, Statamic' => ['voice-analyst', 'The voice guide was longer than Ghostwriter allows and was cut off. Try again, or read fewer collections.', StudioOptions::statamic()];
        yield 'type-analyst, Filament' => ['type-analyst', 'The description of this kind of content was longer than Ghostwriter allows and was cut off. Try again, or model it on fewer records.', StudioOptions::filament()];
        yield 'voice-editor, Filament' => ['voice-editor', 'The voice guide was longer than Ghostwriter allows and was cut off. Try asking for a smaller change.', StudioOptions::filament()];
        yield 'writer, Craft' => ['writer', 'The answer ran past its length limit and was cut off before it finished. Try asking for something shorter.', StudioOptions::craft()];
    }

    #[DataProvider('wholeAgents')]
    public function test_a_draft_or_guide_cut_off_twice_is_reported_not_kept(string $agent, string $message, StudioOptions $options): void
    {
        $this->fake->respond($agent, self::cutOff('<draft>title: Half'));

        try {
            $this->studio(options: $options)->ask($agent, 'Go.');
            $this->fail('A cut-off reply was kept.');
        } catch (Truncated $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame('fake', $exception->provider());
        }

        $limit = Agents::maxTokens($agent);
        $this->assertSame([$limit, min(Studio::MAX_TOKENS_CEILING, $limit * 2)], $this->limits($agent));
    }

    public function test_a_list_cut_off_twice_is_kept_with_a_warning(): void
    {
        $this->fake->respond('kind-finder', self::cutOff("<kinds>\n- title: Case\n  examples: [a, b]\n- title: Cut", 5, 8000), self::cutOff("<kinds>\n- title: Case\n  examples: [a, b]\n- title: Cut", 5, 16000));

        $kinds = $this->studio(options: StudioOptions::craft())->suggestKinds(new KindSurvey('News', 'news', [new KindSample('a', 'A'), new KindSample('b', 'B')]));

        // Craft used to throw here; core keeps the list (§6.6).
        $this->assertSame('Case', $kinds->value[0]->title);
        $this->assertSame([8000, 16000], $this->limits('kind-finder'));
        $this->assertSame([10, 24000], [$kinds->usage->input, $kinds->usage->output]);
        $this->assertSame('Ghostwriter: the kind-finder reply ran out of room at 16000 tokens; keeping what came back.', $this->logs[1]['message']);
    }

    public function test_the_photo_agents_and_the_imagery_analyst_keep_what_came_back(): void
    {
        foreach (['photo-query', 'photo-researcher', 'photo-picker', 'imagery-analyst', 'brief-writer', 'planner'] as $agent) {
            $this->fake->respond($agent, self::cutOff('partial'));

            $this->assertSame('partial', $this->studio()->ask($agent, 'Go.')->text);
            $this->assertCount(2, $this->fake->prompted($agent));
        }
    }

    public function test_a_reply_that_finished_is_asked_for_once(): void
    {
        $this->fake->respond('writer', '<draft>title: x</draft>');

        $this->studio()->ask('writer', 'Go.');

        $this->assertSame([16000], $this->limits('writer'));
        $this->assertSame([], $this->logs);
    }

    public function test_unknown_cut_off_messages_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StudioOptions(cutOffMessages: ['planner' => 'No.']);
    }

    /**
     * @return array<int, int>
     */
    private function limits(string $agent): array
    {
        return array_map(fn (TextRequest $request) => $request->resolvedMaxTokens(), $this->fake->prompted($agent));
    }

    private function writer(): WriterContext
    {
        return new WriterContext(new ContentKind('k', 'K'), '', new Layout('fields'), '');
    }
}
