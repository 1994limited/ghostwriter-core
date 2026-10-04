<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Review\ApplyOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Apply, through FakeProvider: the comments go into the conversation as
 * one message, one reviser call answers every one, its reply is checked
 * and applied under the session's lock, the layouts are re-arranged with
 * no call, and Ghostwriter's one answer message has a result per comment.
 */
final class RevisionTest extends ReviewTestCase
{
    private const VISITS = "## The visits\n\n**November: Cut back.** Prune what needs it.\n\n**January: Feed.** Mulch the beds and [[ask: what else in January]].";

    /**
     * @return array<string, mixed>
     */
    private function data(Session $session): array
    {
        return Draft::parse((string) $this->fresh($session)->draft)->data;
    }

    private function units(Session $session): Units
    {
        $session = $this->fresh($session);

        return Units::fromDraft(Draft::parse((string) $session->draft), Northfold::blocks())->restore($session->units);
    }

    private function revise(Session $session): ApplyOutcome
    {
        $session = $this->fresh($session);

        return $this->comments->revise($session->id, $this->conversation($session), $this->writer(), $this->site(), ['1' => 'Daniel', '2' => 'Priya']);
    }

    public function test_one_call_carries_every_comment_and_only_their_units_change(): void
    {
        $session = $this->drafted();
        $before = $this->units($session);

        $this->fake->respond('reviser', self::reply(<<<'YAML'
            <changes>
            - comment: 1
              reply: Cut each visit to one line. Same visits and months; nothing added.
              units:
                u7: |
                  ## The visits

                  **November: Cut back.** Prune what needs it.

                  **January: Feed.** Mulch the beds and [[ask: what else in January]].
            - comment: 2
              reply: Made it warmer.
              replace:
                - unit: u8
                  exact: "Gardens with mixed borders."
                  with: "Gardens with mixed borders love it."
            - comment: 3
              reply: Yes, it matches the brief, so I left it.
            </changes>
            YAML, 2000, 400));

        $started = $this->send($session, [
            [Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'Make each visit one short line; these read long.'],
            [Scope::text('u8', new TextQuote('Gardens with mixed borders.'), 'Who it suits'), 'Say who it suits more warmly.'],
            [Scope::block(['u9'], 'Call to action'), 'Is "Book a winter visit" right?'],
        ]);

        // One message from the editor, in the conversation.
        $this->assertTrue($started->isWorking());
        $message = $started->lastMessage();
        $this->assertSame('user', $message['role']);
        $this->assertSame([1, 2, 3], array_column($message[Comments::KEY]['items'], 'number'));
        $this->assertStringStartsWith("3 comments on the draft:\n1. On “The visits”: Make each visit", (string) $message['content']);
        $this->assertSame(['sending', 'sending', 'sending'], array_column($this->comments->pins($started), 'status'));
        $this->assertArrayHasKey('u7', $message[Comments::KEY]['items'][0]['hashes']);

        $outcome = $this->revise($session);

        $this->assertSame(['reviser'], array_map(fn ($request) => $request->agent, $this->fake->requests()), 'one call for every comment');
        $request = $this->sent('reviser');
        $this->assertSame([8000, 'medium'], [$request->resolvedMaxTokens(), $request->resolvedEffort()?->value]);
        $this->assertStringContainsString('Plain and warm.', $request->instructions, 'the writer\'s voice and rules');
        $this->assertStringContainsString('## Revising from comments', $request->instructions);
        $this->assertStringContainsString("1. On \"The visits\" (unit u7). By Daniel.\n   Make each visit one short line; these read long.", $request->prompt);
        $this->assertStringContainsString('2. On "Who it suits" (unit u8), about: "Gardens with mixed borders.". By Daniel.', $request->prompt);
        $this->assertStringContainsString("<units>\nu7: |\n  ## The visits", $request->prompt);
        $this->assertStringNotContainsString('u6: |', $request->prompt, 'only the units the comments may change');

        $this->assertSame([1, 2], $outcome->changed);
        $this->assertSame([3], $outcome->replied);

        $after = $this->units($session);
        $this->assertSame(self::VISITS, $after->get('u7')?->markdown);
        $this->assertStringContainsString('Gardens with mixed borders love it.', (string) $after->get('u8')?->markdown);

        foreach ($before->all() as $unit) {
            if (! in_array($unit->id, ['u7', 'u8'], true)) {
                $this->assertSame($unit->markdown, $after->get($unit->id)?->markdown, "{$unit->id} is untouched");
            }
        }

        // One answer message, with a result per comment.
        $fresh = $this->fresh($session);
        $this->assertFalse($fresh->isWorking());
        $answer = $fresh->lastMessage();
        $this->assertSame('assistant', $answer['role']);
        $this->assertSame(count($fresh->messages) - 2, $answer[Comments::KEY]['answers']);
        $this->assertSame(['changed', 'changed', 'replied'], array_column($answer[Comments::KEY]['results'], 'outcome'));
        $this->assertSame('Revised 2 blocks from your comments: The visits, Who it suits. Nothing else changed. Replied to 1 comment without changing anything.', $answer['content']);
        $this->assertGreaterThanOrEqual(2000, $fresh->usage['input']);

        $visits = $this->pin($session, 1);
        $this->assertSame(['changed', 'Changed'], [$visits['status'], $visits['state']]);
        $this->assertCount(1, $visits['changes']);
        $this->assertContains(['-', 'the shrubs that need '], $visits['changes'][0]['diff']);
        $this->assertTrue($visits['canPutBack']);
        $this->assertSame(['page_builder/1'], $visits['blocks']);
        $this->assertSame('Gardens with mixed borders love it.', $this->pin($session, 2)['scope']['quote']['exact'], 'a comment on words now points at the words that took their place');
        $this->assertSame('Yes, it matches the brief, so I left it.', $this->pin($session, 3)['reply']);

        // Every layout follows the new text, with no call.
        $this->assertStringContainsString('Prune what needs it.', (string) json_encode($this->layouts->draftData($fresh, $this->site(), 'p1')));
        $this->assertCount(1, $this->fake->requests());

        // The next comments carry on the numbers.
        $this->assertSame(4, Comments::nextNumber($fresh));
    }

    public function test_at_most_twelve_go_in_one_message_and_none_is_refused(): void
    {
        $session = $this->drafted();

        try {
            $this->send($session, array_map(fn (int $i) => [Scope::page(), "Comment {$i}"], range(1, 13)));
            $this->fail('Sent thirteen.');
        } catch (Conflict $conflict) {
            $this->assertSame('Apply at most 12 comments at a time.', $conflict->getMessage());
        }

        try {
            $this->send($session, [[Scope::page(), '   ']]);
            $this->fail('Sent nothing.');
        } catch (Conflict $conflict) {
            $this->assertSame('There are no comments to apply.', $conflict->getMessage());
        }

        $this->assertFalse($this->fresh($session)->isWorking());

        $this->fake->respond('reviser', self::reply("<changes>\n".implode("\n", array_map(fn ($i) => "- comment: {$i}\n  reply: Nothing to change.", range(1, 12)))."\n</changes>"));
        $this->send($session, array_map(fn (int $i) => [Scope::page(), "Comment {$i}"], range(1, 12)));
        $outcome = $this->revise($session);

        $this->assertCount(1, $this->fake->prompted('reviser'));
        $this->assertSame(range(1, 12), $outcome->replied);
    }

    public function test_a_fact_in_a_comment_fills_an_ask_and_is_labelled_as_the_editors(): void
    {
        $session = $this->drafted();

        $this->fake->respond('reviser', self::reply(<<<'YAML'
            <changes>
            - comment: 1
              reply: Added the grasses to January.
              replace:
                - unit: u7
                  exact: "[[ask: what else in January]]"
                  with: "cut back the grasses"
            </changes>
            YAML));
        $this->send($session, [[Scope::block(['u7'], 'The visits'), 'In January we also cut back the grasses.']]);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->changed);
        $this->assertStringContainsString('Mulch the beds and cut back the grasses.', (string) $this->units($session)->get('u7')?->markdown);
        $pin = $this->pin($session, 1);
        $this->assertSame([['ask' => 'what else in January', 'value' => 'cut back the grasses', 'by' => '1']], $pin['changes'][0]['filled']);
        $this->assertSame('Added the grasses to January. Filled in from your comment: “cut back the grasses”.', $pin['reply']);
    }

    public function test_mixed_outcomes_a_refusal_says_why_and_the_rest_apply(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Tidied the heading and the intro.\n  units:\n    u9: Book a visit\n    u6: Winter sets a garden up.\n- comment: 2\n  reply: Friendlier.\n  units:\n    u10: Book yours\n- comment: 3\n  reply: Filled it in.\n  replace:\n    - unit: u7\n      exact: \"[[ask: what else in January]]\"\n      with: \"rake the leaves\"\n- comment: 4\n  reply: Added the price.\n  replace:\n    - unit: u8\n      exact: \"Gardens with mixed borders.\"\n      with: \"Gardens with mixed borders, from £75 a visit.\"\n</changes>"));

        $this->send($session, [
            [Scope::block(['u9'], 'Call to action'), 'Shorter button.'],
            [Scope::block(['u10'], 'Button'), 'Friendlier.'],
            [Scope::block(['u7'], 'The visits'), 'Fill in January, please.'],
            [Scope::block(['u8'], 'Who it suits'), 'Say what a visit costs.'],
        ]);
        $outcome = $this->revise($session);

        $this->assertSame([1 => [RevisionValidator::SCOPE], 3 => [RevisionValidator::MARKERS], 4 => [RevisionValidator::FACTS]], $outcome->refused);
        $this->assertSame([2], $outcome->changed);
        $this->assertSame('Book a winter visit', $this->data($session)['page_builder'][3]['heading']);
        $this->assertSame('Book yours', $this->data($session)['page_builder'][3]['button']);
        $this->assertStringContainsString('[[ask: what else in January]]', (string) $this->units($session)->get('u7')?->markdown);

        $this->assertSame(['refused', 'Not applied'], [$this->pin($session, 1)['status'], $this->pin($session, 1)['state']]);
        $this->assertStringContainsString('without touching other parts of the page', (string) $this->pin($session, 1)['reply']);
        $this->assertStringContainsString('gap left for you to fill', (string) $this->pin($session, 3)['reply']);
        $this->assertSame('That change needed something I don’t have (£75). Say it in a new comment and apply again.', $this->pin($session, 4)['reply']);
        $this->assertStringContainsString('3 comments couldn’t be applied', (string) $this->fresh($session)->lastMessage()['content']);
    }

    public function test_a_unit_someone_changed_during_the_run_is_skipped_and_reported(): void
    {
        $session = $this->drafted();
        $this->send($session, [[Scope::block(['u9'], 'Call to action'), 'Warmer heading.'], [Scope::block(['u10'], 'Button'), 'Friendlier.']]);

        // While the reviser works, the heading changes (an addon's own write).
        $this->fake->respond('reviser', function () use ($session) {
            $this->guard->change($session->id, function (Session $session) {
                $data = Draft::parse((string) $session->draft)->data;
                $data['page_builder'][3]['heading'] = 'Book a winter visit today';
                $before = $session->draft;
                $session->draft = self::yaml($data);
                $this->layouts->afterEdit($session, $before, $this->site());
            });

            return self::reply("<changes>\n- comment: 1\n  reply: Warmer.\n  units:\n    u9: Come and see us this winter\n- comment: 2\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>");
        });

        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->conflicted);
        $this->assertSame([2], $outcome->changed);
        $data = $this->data($session);
        $this->assertSame('Book a winter visit today', $data['page_builder'][3]['heading'], 'the newer edit is never overwritten');
        $this->assertSame('Book yours', $data['page_builder'][3]['button']);
        $this->assertSame('skipped', $this->pin($session, 1)['status']);
        $this->assertStringContainsString('changed this block while I was working', (string) $this->pin($session, 1)['reply']);
    }

    public function test_only_one_apply_runs_at_a_time_and_it_shares_the_chats_claim(): void
    {
        $session = $this->drafted();
        $this->send($session, [[Scope::page(), 'Warmer.']]);

        try {
            $this->send($session, [[Scope::page(), 'And shorter.']], $this->priya);
            $this->fail('A second Apply ran alongside the first.');
        } catch (Busy $busy) {
            $this->assertSame('1', $busy->waitingOn, '"Daniel is waiting on Ghostwriter"');
        }

        try {
            $this->guard->send($session->id, 'Make it longer.', $this->priya);
            $this->fail('A chat message ran alongside Apply.');
        } catch (Busy) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, count(array_filter($this->fresh($session)->messages, fn (array $message) => isset($message[Comments::KEY]['items']))));
    }

    public function test_a_comment_answered_with_a_reply_only_is_replied(): void
    {
        $session = $this->drafted();
        $draft = $this->fresh($session)->draft;
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: I can't change images; choose another in the image picker.\n</changes>"));

        $this->send($session, [[Scope::block(['u5'], 'Hero image'), 'A brighter photo.']]);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->replied);
        $this->assertSame($draft, $this->fresh($session)->draft, 'nothing changed');
        $pin = $this->pin($session, 1);
        $this->assertSame(['replied', 'Replied', [], false], [$pin['status'], $pin['state'], $pin['changes'], $pin['canPutBack']]);
    }

    public function test_a_comment_may_ask_for_its_block_to_be_laid_out_anew(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply(<<<'YAML'
            <changes>
            - comment: 1
              reply: Put the visits into cards. The words are the same.
              layout:
                - type: text
                  place: { body: u6 }
                - type: section
                  place: { heading: "u7#1" }
                  children:
                    - { type: card, place: { heading: "u7#2:lead", body: "u7#2:rest" } }
                    - { type: card, place: { heading: "u7#3:lead", body: "u7#3:rest" } }
                - type: text
                  place: { body: u8 }
            </changes>
            YAML));

        $this->send($session, [[Scope::block(['u6', 'u7', 'u8'], 'Text', 'w', 'page_builder/1'), 'Make the visits cards.']]);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->laidOut);
        $data = $this->data($session);
        $this->assertSame(['hero', 'text', 'section', 'text', 'spacer', 'cta'], array_column($data['page_builder'], 'type'), 'the writer\'s layout is the draft, so the draft is re-arranged');
        $this->assertSame('Winter is when a garden is set up for the year.', $this->units($session)->get('u6')?->markdown, 'ids carried with the words');
        $this->assertSame('changed', $this->pin($session, 1)['status']);
        $this->assertGreaterThan(1, count($this->pin($session, 1)['blocks']), 'spans the blocks its words are in now');
    }

    public function test_a_layout_the_site_cannot_show_is_kept_and_said_so(): void
    {
        $session = $this->drafted();
        $draft = $this->fresh($session)->draft;
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Made it a carousel.\n  layout:\n    - type: carousel\n      place: { slides: [u9, u10] }\n</changes>"));

        $this->send($session, [[Scope::block(['u9', 'u10'], 'Call to action'), 'Make this a carousel.']]);
        $outcome = $this->revise($session);

        $this->assertSame([], $outcome->laidOut);
        $this->assertSame($draft, $this->fresh($session)->draft);
        $this->assertStringContainsString('I kept the layout', (string) $this->pin($session, 1)['reply']);
    }

    public function test_put_it_back_resolve_and_reopen_are_noted_on_the_answer(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>"));
        $this->send($session, [[Scope::block(['u10'], 'Button'), 'Friendlier.']]);
        $this->revise($session);
        $this->fake->reset();
        $answer = $this->pin($session, 1)['answer'];
        $this->assertIsInt($answer);

        $this->comments->putBack($session->id, $this->priya, $answer, 1, $this->site());

        $this->assertSame('Book now', $this->data($session)['page_builder'][3]['button']);
        $pin = $this->pin($session, 1);
        $this->assertSame(['changed', '2', false], [$pin['status'], $pin['putBack']['by'], $pin['canPutBack']], 'putting back keeps the state');

        $this->comments->resolve($session->id, $this->daniel, $answer, 1);
        $this->assertSame(['resolved', 'Resolved', '1'], [$this->pin($session, 1)['status'], $this->pin($session, 1)['state'], $this->pin($session, 1)['resolved']['by']]);
        $this->comments->resolve($session->id, $this->daniel, $answer, 1, false);
        $this->assertSame('changed', $this->pin($session, 1)['status']);
        $this->fake->assertNothingSent();

        try {
            $this->comments->resolve($session->id, $this->daniel, $answer, 9);
            $this->fail('Resolved a comment that isn\'t there.');
        } catch (NotFound) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(Conflict::class);
        $this->comments->putBack($session->id, $this->priya, $answer, 1, $this->site());
    }

    public function test_a_failed_call_answers_every_comment_and_frees_the_piece(): void
    {
        $session = $this->drafted();
        $this->fake->failWith('reviser', new ProviderException('The model is overloaded.', 'fake'));

        $this->send($session, [[Scope::page(), 'Warmer.']]);
        $outcome = $this->revise($session);

        $this->assertSame('The model is overloaded.', $outcome->failed);
        $fresh = $this->fresh($session);
        $this->assertFalse($fresh->isWorking());
        $this->assertSame('failed', $this->pin($session, 1)['status']);
        $this->assertStringContainsString('overloaded', (string) $fresh->lastMessage()['content']);
        $this->assertNull(Comments::unanswered($fresh));

        // A job that stopped is answered the same way, once.
        $this->send($session, [[Scope::page(), 'Shorter.']]);
        $this->comments->fail($session->id, 'it took too long');
        $this->comments->fail($session->id, 'it took too long');
        $this->assertSame('failed', $this->pin($session, 2)['status']);
        $this->assertSame(2, count(array_filter($this->fresh($session)->messages, fn (array $message) => isset($message[Comments::KEY]['answers']))));
    }

    public function test_a_cut_off_revision_is_asked_again_then_fails_cleanly(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::cutOff('<changes>'), self::cutOff('<changes>'));

        $this->send($session, [[Scope::page(), 'Warmer.']]);
        $outcome = $this->revise($session);

        $this->assertCount(2, $this->fake->prompted('reviser'));
        $this->assertStringContainsString('cut off', (string) $outcome->failed);
        $this->assertSame('failed', $this->pin($session, 1)['status']);
    }
}
