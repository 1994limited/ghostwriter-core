<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Review\ApplyOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Review\ThreadStatus;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Apply, through FakeProvider: one reviser call for every comment, its
 * reply checked and applied under the session's lock, the layouts
 * re-arranged with no call, and a before and after on each thread.
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
        $visits = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'Make each visit one short line; these read long.');
        $suits = $this->comments->add($session->id, $this->priya, Scope::text('u8', new TextQuote('Gardens with mixed borders.'), 'Who it suits'), 'Say who it suits more warmly.');
        $question = $this->comments->add($session->id, $this->priya, Scope::block(['u9'], 'Call to action'), 'Is "Book a winter visit" right?');
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

        $started = $this->comments->apply($session->id, $this->daniel);
        $this->assertTrue($started->isWorking());
        $this->assertSame(['sending', 'sending', 'sending'], array_column($this->comments->threads($started), 'status'));
        $this->assertStringStartsWith('Applied 3 comments:', (string) $started->lastMessage()['content']);

        $outcome = $this->revise($session);

        $this->assertSame(['reviser'], array_map(fn ($request) => $request->agent, $this->fake->requests()), 'one call for every comment');
        $request = $this->sent('reviser');
        $this->assertSame([8000, 'medium'], [$request->resolvedMaxTokens(), $request->resolvedEffort()?->value]);
        $this->assertStringContainsString('Plain and warm.', $request->instructions, 'the writer\'s voice and rules');
        $this->assertStringContainsString('## Revising from comments', $request->instructions);
        $this->assertStringNotContainsString('Extras you may prepare', $request->instructions);
        $this->assertStringContainsString("1. On \"The visits\" (unit u7). By Daniel.\n   Make each visit one short line; these read long.", $request->prompt);
        $this->assertStringContainsString('2. On "Who it suits" (unit u8), about: "Gardens with mixed borders.". By Priya.', $request->prompt);
        $this->assertStringContainsString("<units>\nu7: |\n  ## The visits", $request->prompt);
        $this->assertStringNotContainsString('u6: |', $request->prompt, 'only the units the comments may change');
        $this->assertStringContainsString("<layout>\nThe page is laid out as \"As written\":\npage_builder: hero [u3 u4 u5], text [u6 u7 u8], spacer [], cta [u9 u10]", $request->prompt);

        $this->assertSame([1, 2], $outcome->changed);
        $this->assertSame([3], $outcome->replied);
        $this->assertSame([], $outcome->refused);

        $after = $this->units($session);
        $this->assertSame(self::VISITS, $after->get('u7')?->markdown);
        $this->assertStringContainsString('Gardens with mixed borders love it.', (string) $after->get('u8')?->markdown);

        foreach ($before->all() as $unit) {
            if (! in_array($unit->id, ['u7', 'u8'], true)) {
                $this->assertSame($unit->markdown, $after->get($unit->id)?->markdown, "{$unit->id} is untouched");
            }
        }

        $this->assertSame($before->ids(), $after->ids(), 'every unit keeps its id');

        $fresh = $this->fresh($session);
        $this->assertFalse($fresh->isWorking());
        $this->assertSame(['changed', 'changed', 'replied'], array_column($this->comments->threads($fresh), 'status'));
        $this->assertSame(['Changed', 'Changed', 'Replied'], array_column($this->comments->threads($fresh), 'state'));
        $this->assertSame('Revised 2 blocks from your comments: The visits, Who it suits. Nothing else changed. Replied to 1 comment without changing anything.', $fresh->lastMessage()['content'] ?? null);
        $this->assertGreaterThanOrEqual(2000, $fresh->usage['input']);

        $changes = $this->comments->changes($fresh, $visits->id);
        $this->assertCount(1, $changes);
        $this->assertSame('u7', $changes[0]['unit']);
        $this->assertStringContainsString('Prune the shrubs that need it.', $changes[0]['before']);
        $this->assertContains(['-', 'the shrubs that need '], $changes[0]['diff']);
        $this->assertTrue($changes[0]['canPutBack']);
        $this->assertSame('Gardens with mixed borders love it.', $this->comments->review($fresh)->find($suits->id)->scope->quote?->exact, 'a comment on words now points at the words that took their place');
        $this->assertSame(ThreadStatus::Changed, $this->comments->review($fresh)->find($suits->id)->status, 'not detached by its own change');
        $this->assertSame('Yes, it matches the brief, so I left it.', $this->comments->review($fresh)->find($question->id)->lastAnswer()?->body);

        // Every layout follows the new text, with no call.
        $this->assertStringContainsString('Prune what needs it.', (string) json_encode($this->layouts->draftData($fresh, $this->site(), 'p1')));
        $this->assertFalse($this->layouts->plans($fresh)->get('p1')?->stale);
        $this->assertCount(1, $this->fake->requests());
    }

    public function test_five_comments_are_still_one_call_and_more_than_twelve_wait(): void
    {
        $session = $this->drafted();

        for ($i = 1; $i <= 14; $i++) {
            $this->comments->add($session->id, $this->daniel, Scope::page(), "Comment {$i}");
        }

        $this->fake->respond('reviser', self::reply("<changes>\n".implode("\n", array_map(fn ($i) => "- comment: {$i}\n  reply: Nothing to change.", range(1, 12)))."\n</changes>"));
        $started = $this->comments->apply($session->id, $this->daniel);
        $this->assertSame(12, count($this->comments->review($started)->sending()));
        $this->assertSame(2, $started->lastMessage()['review']['waiting'] ?? null);

        $outcome = $this->revise($session);

        $this->assertCount(1, $this->fake->prompted('reviser'));
        $this->assertSame(range(1, 12), $outcome->replied);
        $this->assertSame([13, 14], array_map(fn ($thread) => $thread->number, $this->comments->review($this->fresh($session))->open()));
    }

    public function test_a_fact_in_a_comment_fills_an_ask_and_is_labelled_as_the_editors(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits'), 'In January we also cut back the grasses.');

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
        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->changed);
        $this->assertStringContainsString('Mulch the beds and cut back the grasses.', (string) $this->units($session)->get('u7')?->markdown);
        $change = $this->comments->changes($this->fresh($session), $thread->id)[0];
        $this->assertSame([['ask' => 'what else in January', 'value' => 'cut back the grasses', 'by' => '1']], $change['filled']);
        $this->assertSame('Added the grasses to January. Filled in from your comment: “cut back the grasses”.', $this->comments->review($this->fresh($session))->find($thread->id)->lastAnswer()?->body);
    }

    public function test_an_ask_filled_with_something_the_editor_never_said_is_refused(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits'), 'Fill in January, please.');

        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Filled it in.\n  replace:\n    - unit: u7\n      exact: \"[[ask: what else in January]]\"\n      with: \"rake the leaves\"\n</changes>"));
        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([1 => [RevisionValidator::MARKERS]], $outcome->refused);
        $this->assertStringContainsString('[[ask: what else in January]]', (string) $this->units($session)->get('u7')?->markdown);
        $review = $this->comments->review($this->fresh($session));
        $this->assertSame(ThreadStatus::Open, $review->find($thread->id)->status, 'back to Not sent');
        $this->assertStringContainsString('gap left for you to fill', $review->find($thread->id)->notes[1]->body);
        $this->assertSame('1 comment couldn’t be applied and is back to Not sent.', $this->fresh($session)->lastMessage()['content'] ?? null);
    }

    public function test_an_out_of_scope_change_is_refused_and_the_rest_applied(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::block(['u9'], 'Call to action'), 'Shorter button.');
        $this->comments->add($session->id, $this->daniel, Scope::block(['u10'], 'Button'), 'Friendlier.');
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Tidied the heading and the intro.\n  units:\n    u9: Book a visit\n    u6: Winter sets a garden up.\n- comment: 2\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>"));

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([1 => [RevisionValidator::SCOPE]], $outcome->refused);
        $this->assertSame([2], $outcome->changed);
        $this->assertSame('Book a winter visit', $this->data($session)['page_builder'][3]['heading']);
        $this->assertSame('Book yours', $this->data($session)['page_builder'][3]['button']);
    }

    public function test_a_unit_someone_changed_during_the_run_is_skipped_and_reported(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::block(['u9'], 'Call to action'), 'Warmer heading.');
        $this->comments->add($session->id, $this->daniel, Scope::block(['u10'], 'Button'), 'Friendlier.');
        $this->comments->apply($session->id, $this->daniel);

        // While the reviser works, the heading changes (a stale run recovered, an addon's own write).
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
        $thread = $this->comments->review($this->fresh($session))->all()[0];
        $this->assertSame(ThreadStatus::Open, $thread->status);
        $this->assertStringContainsString('changed this block while I was working', $thread->notes[1]->body);
    }

    public function test_only_one_apply_runs_at_a_time(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');
        $this->comments->apply($session->id, $this->daniel);
        $later = $this->comments->add($session->id, $this->priya, Scope::page(), 'And shorter.');

        try {
            $this->comments->apply($session->id, $this->priya);
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

        $this->assertSame(ThreadStatus::Open, $this->comments->review($this->fresh($session))->find($later->id)->status, 'it waits for the next Apply');
    }

    public function test_a_comment_answered_with_a_reply_only_is_replied(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::block(['u5'], 'Hero image'), 'A brighter photo.');
        $draft = $this->fresh($session)->draft;
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: I can't change images; choose another in the image picker.\n</changes>"));

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->replied);
        $this->assertSame($draft, $this->fresh($session)->draft, 'nothing changed');
        $this->assertSame('Replied', $this->comments->threads($this->fresh($session))[0]['state']);
        $this->assertSame([], $this->comments->changes($this->fresh($session), $thread->id));
    }

    public function test_a_comment_may_ask_for_its_block_to_be_laid_out_anew(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::block(['u6', 'u7', 'u8'], 'Text', 'w', 'page_builder/1'), 'Make the visits cards.');
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

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([1], $outcome->laidOut);
        $data = $this->data($session);
        $this->assertSame(['hero', 'text', 'section', 'text', 'spacer', 'cta'], array_column($data['page_builder'], 'type'), 'the writer\'s layout is the draft, so the draft is re-arranged');
        $this->assertSame('November: Cut back', $data['page_builder'][2]['children'][0]['heading']);
        $units = $this->units($session);
        $this->assertSame('Winter is when a garden is set up for the year.', $units->get('u6')?->markdown, 'ids carried with the words');
        $this->assertSame(ThreadStatus::Changed, $this->comments->review($this->fresh($session))->all()[0]->status);
    }

    public function test_a_layout_the_site_cannot_show_is_kept_and_said_so(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::block(['u9', 'u10'], 'Call to action'), 'Make this a carousel.');
        $draft = $this->fresh($session)->draft;
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Made it a carousel.\n  layout:\n    - type: carousel\n      place: { slides: [u9, u10] }\n</changes>"));

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame([], $outcome->laidOut);
        $this->assertSame($draft, $this->fresh($session)->draft);
        $this->assertStringContainsString('I kept the layout', (string) $this->comments->review($this->fresh($session))->all()[0]->lastAnswer()?->body);
    }

    public function test_put_it_back_restores_the_text_through_the_hash_check(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::block(['u10'], 'Button'), 'Friendlier.');
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>"));
        $this->comments->apply($session->id, $this->daniel);
        $this->revise($session);
        $this->fake->reset();

        $this->assertSame('Book now', $this->comments->beforeData($this->fresh($session), $thread->id, $this->site())['page_builder'][3]['button'], 'Show before');

        $this->comments->putBack($session->id, $this->priya, $thread->id, $this->site());

        $this->assertSame('Book now', $this->data($session)['page_builder'][3]['button']);
        $back = $this->comments->review($this->fresh($session))->find($thread->id);
        $this->assertSame(ThreadStatus::Changed, $back->status, 'putting back keeps the state');
        $this->assertSame(['Put back.', '2'], [end($back->notes)->body, end($back->notes)->by]);
        $this->fake->assertNothingSent();

        $this->expectException(Conflict::class);
        $this->comments->putBack($session->id, $this->priya, $thread->id, $this->site());
    }

    public function test_a_failed_call_sends_every_comment_back(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');
        $this->fake->failWith('reviser', new ProviderException('The model is overloaded.', 'fake'));

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertSame('The model is overloaded.', $outcome->failed);
        $fresh = $this->fresh($session);
        $this->assertFalse($fresh->isWorking());
        $this->assertSame(ThreadStatus::Open, $this->comments->review($fresh)->all()[0]->status);
        $this->assertStringContainsString('overloaded', (string) $fresh->lastMessage()['content']);
    }

    public function test_a_cut_off_revision_is_asked_again_then_fails_cleanly(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');
        $this->fake->respond('reviser', self::cutOff('<changes>'), self::cutOff('<changes>'));

        $this->comments->apply($session->id, $this->daniel);
        $outcome = $this->revise($session);

        $this->assertCount(2, $this->fake->prompted('reviser'));
        $this->assertStringContainsString('cut off', (string) $outcome->failed);
        $this->assertSame(ThreadStatus::Open, $this->comments->review($this->fresh($session))->all()[0]->status);
    }

    public function test_nothing_to_apply_is_refused_and_listing_or_resolving_calls_nothing(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');
        $this->comments->resolve($session->id, $this->daniel, $thread->id);
        $this->comments->threads($this->fresh($session));

        try {
            $this->comments->apply($session->id, $this->daniel);
            $this->fail('Applied nothing.');
        } catch (Conflict) {
            $this->assertFalse($this->fresh($session)->isWorking());
        }

        $this->fake->assertNothingSent();
    }
}
