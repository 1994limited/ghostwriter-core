<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Review\Change;
use NineteenNinetyFour\Ghostwriter\Core\Review\Review;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Review\ScopeKind;
use NineteenNinetyFour\Ghostwriter\Core\Review\ThreadStatus;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use PHPUnit\Framework\TestCase;

/** The comments model: states, limits, the version, and following the text. */
final class ReviewTest extends TestCase
{
    private Viewer $daniel;

    private Viewer $priya;

    protected function setUp(): void
    {
        $this->daniel = new Viewer(1);
        $this->priya = new Viewer(2);
    }

    public function test_a_comment_is_not_sent_numbered_and_bumps_the_version(): void
    {
        $review = new Review;
        $one = $review->add(Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'Make each visit one line.', $this->daniel);
        $two = $review->add(Scope::page(), 'Warmer, please.', $this->priya);

        $this->assertSame([1, 2], [$one->number, $two->number]);
        $this->assertSame(ThreadStatus::Open, $one->status);
        $this->assertSame('Not sent', $one->status->label());
        $this->assertSame(2, $review->version);
        $this->assertSame([$one, $two], $review->open());
        $this->assertSame(1, $one->startedBy);

        $review->remove($one->id);
        $three = $review->add(Scope::page(), 'Shorter.', $this->daniel);
        $this->assertSame(3, $three->number, 'a pin number is never reused');
    }

    public function test_it_round_trips(): void
    {
        $review = new Review;
        $thread = $review->add(Scope::text('u8', new TextQuote('Gardens with mixed borders.', '', ' [Talk'), 'Who it suits', 'w', 'page_builder/1'), 'Say who it is not for.', $this->daniel);
        $review->send(['u8' => 'abc']);
        $review->answer($thread->id, 'Added a line.', [new Change('u8', 'before', 'after', 3, [['ask' => 'price', 'value' => '£60', 'by' => 1]])]);

        $back = Review::fromArray(json_decode((string) json_encode($review->toArray()), true));

        $this->assertSame($review->toArray(), $back->toArray());
        $this->assertSame(ScopeKind::Text, $back->find($thread->id)->scope->kind);
        $this->assertSame('Gardens with mixed borders.', $back->find($thread->id)->scope->quote?->exact);
        $this->assertSame(2, $back->next);
    }

    public function test_the_states_from_not_sent_to_resolved_and_back(): void
    {
        $review = new Review;
        $changed = $review->add(Scope::block(['u7']), 'One line each.', $this->daniel);
        $replied = $review->add(Scope::block(['u9']), 'Is this right?', $this->priya);

        $sent = $review->send(['u7' => 'h7', 'u9' => 'h9', 'u8' => 'h8']);
        $this->assertSame([$changed, $replied], $sent);
        $this->assertSame(ThreadStatus::Sending, $changed->status);
        $this->assertSame('Revising', $changed->status->label());
        $this->assertSame(['u7' => 'h7'], $changed->hashes, 'only its own units are recorded');
        $this->assertSame($review->version, $changed->sentAtVersion);

        $review->answer($changed->id, 'Cut each to one line.', [new Change('u7', 'a', 'b', $review->version)]);
        $review->answer($replied->id, 'It is what the brief says.');
        $this->assertSame([ThreadStatus::Changed, ThreadStatus::Replied], [$changed->status, $replied->status]);

        $review->resolve($changed->id, $this->priya);
        $this->assertSame([ThreadStatus::Resolved, 2], [$changed->status, $changed->resolvedBy]);
        $review->reopen($changed->id, ['u7', 'u8']);
        $this->assertSame(ThreadStatus::Changed, $changed->status, 'reopened, it is what Ghostwriter left it');
        $review->resolve($replied->id, $this->daniel);
        $review->reopen($replied->id);
        $this->assertSame(ThreadStatus::Replied, $replied->status);

        $review->reply($replied->id, 'Then cut it.', $this->daniel);
        $this->assertSame(ThreadStatus::Open, $replied->status, 'a reply goes with the next Apply');
        $this->assertSame(['Then cut it.'], array_map(fn ($note) => $note->body, $replied->asks()), 'only what was asked since the answer is sent');
    }

    public function test_reopening_a_thread_whose_text_is_gone_detaches_it(): void
    {
        $review = new Review;
        $thread = $review->add(Scope::block(['u7']), 'One line each.', $this->daniel);
        $review->resolve($thread->id, $this->daniel);
        $review->reopen($thread->id, ['u8']);

        $this->assertSame(ThreadStatus::Detached, $thread->status);
    }

    public function test_a_comment_can_be_edited_only_before_it_is_sent(): void
    {
        $review = new Review;
        $thread = $review->add(Scope::page(), 'Shorter.', $this->daniel);
        $review->edit($thread->id, 'Much shorter.');
        $this->assertSame('Much shorter.', $thread->comment()->body);

        $review->send([]);
        $this->expectException(Conflict::class);
        $review->edit($thread->id, 'Shorter still.');
    }

    public function test_a_thread_being_revised_cannot_be_replied_to_resolved_or_deleted(): void
    {
        $review = new Review;
        $thread = $review->add(Scope::page(), 'Shorter.', $this->daniel);
        $review->send([]);

        foreach ([
            fn () => $review->reply($thread->id, 'And warmer.', $this->daniel),
            fn () => $review->resolve($thread->id, $this->daniel),
            fn () => $review->remove($thread->id),
        ] as $refused) {
            try {
                $refused();
                $this->fail('A thread being revised was changed.');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }
        }

        $later = $review->add(Scope::page(), 'New comments still queue.', $this->priya);
        $this->assertSame(ThreadStatus::Open, $later->status);
    }

    public function test_limits(): void
    {
        $review = new Review;

        for ($i = 0; $i < Review::MAX_THREADS; $i++) {
            $review->add(Scope::page(), "Comment {$i}", $this->daniel);
        }

        try {
            $review->add(Scope::page(), 'One more', $this->daniel);
            $this->fail('More than the limit of threads.');
        } catch (Conflict $conflict) {
            $this->assertStringContainsString('100 comments', $conflict->getMessage());
        }

        $sent = $review->send([]);
        $this->assertCount(Review::PER_APPLY, $sent, 'one Apply sends at most 12');
        $this->assertSame(range(1, 12), array_map(fn ($thread) => $thread->number, $sent));
        $this->assertCount(Review::MAX_THREADS - Review::PER_APPLY, $review->open());

        $thread = $review->all()[50];

        for ($i = 1; $i < Review::MAX_NOTES; $i++) {
            $review->reply($thread->id, "Reply {$i}", $this->daniel);
        }

        $this->expectException(Conflict::class);
        $review->reply($thread->id, 'Too many', $this->daniel);
    }

    public function test_an_empty_comment_and_an_unknown_thread_are_refused(): void
    {
        $review = new Review;

        try {
            $review->add(Scope::page(), '  ', $this->daniel);
            $this->fail('An empty comment was added.');
        } catch (Conflict) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(NotFound::class);
        $review->resolve('nope', $this->daniel);
    }

    public function test_a_note_is_cut_to_two_thousand_characters(): void
    {
        $review = new Review;
        $thread = $review->add(Scope::page(), str_repeat('a', 2500), $this->daniel);

        $this->assertSame(2000, mb_strlen($thread->comment()->body));
    }

    public function test_threads_follow_their_units_and_detach_when_the_text_is_gone(): void
    {
        $units = Units::fromDraft(Northfold::blocksDraft(), Northfold::blocks());
        $review = new Review;
        $block = $review->add(Scope::block(['u7', 'u8']), 'Tighter.', $this->daniel);
        $text = $review->add(Scope::text('u8', new TextQuote('Gardens with mixed borders.')), 'Who else?', $this->daniel);
        $gone = $review->add(Scope::block(['u42']), 'About text since rewritten.', $this->daniel);
        $page = $review->add(Scope::page(), 'Warmer.', $this->daniel);
        $extra = $review->add(Scope::block(['x1.1']), 'Is this right?', $this->daniel);

        $this->assertTrue($review->reanchor($units, ['x1.1']));
        $this->assertSame([ThreadStatus::Open, ThreadStatus::Open, ThreadStatus::Detached, ThreadStatus::Open, ThreadStatus::Open], [$block->status, $text->status, $gone->status, $page->status, $extra->status]);
        $this->assertSame(['u42'], $gone->scope->units, 'a detached thread keeps what it was about, to show');
        $this->assertFalse($review->reanchor($units, ['x1.1']), 'nothing changed the second time');

        $draft = Northfold::blocksDraft();
        $draft['page_builder'][1]['body'] = str_replace('Gardens with mixed borders.', 'Gardens of every size.', Northfold::BODY);
        $after = Units::fromDraft($draft, Northfold::blocks())->restore($units->sidecar());
        $review->reanchor($after, []);

        $this->assertSame(ThreadStatus::Detached, $text->status, 'its quoted words are gone');
        $this->assertSame(ThreadStatus::Open, $block->status, 'the block is still there');
        $this->assertSame(ThreadStatus::Detached, $extra->status, 'the extra was deleted');

        $review->reanchor($units, ['x1.1']);
        $this->assertSame([ThreadStatus::Open, ThreadStatus::Open], [$text->status, $extra->status], 'the words came back');
    }

    public function test_a_quote_found_only_fuzzily_is_taken_again_from_the_text(): void
    {
        $units = Units::fromDraft(Northfold::blocksDraft(), Northfold::blocks());
        $review = new Review;
        $thread = $review->add(Scope::text('u7', new TextQuote('Prune the shrubs that needs it.')), 'Which shrubs?', $this->daniel);

        $review->reanchor($units);

        $this->assertSame(ThreadStatus::Open, $thread->status);
        $this->assertSame('Prune the shrubs that need it.', $thread->scope->quote?->exact);
    }

    public function test_a_scope_says_what_a_revision_may_change(): void
    {
        $units = Units::fromDraft(Northfold::blocksDraft(), Northfold::blocks());

        $this->assertSame($units->ids(), Scope::page()->editableUnits($units));
        $this->assertSame([...$units->ids(), 'x1.1'], Scope::page()->editableUnits($units, ['x1.1']));
        $this->assertSame(['u7', 'x1.1'], Scope::block(['u7', 'u99', 'x1.1'])->editableUnits($units));
        $this->assertSame(['u8'], Scope::text('u8', new TextQuote('mixed borders'))->editableUnits($units));
        $this->assertSame(['u1'], Scope::field('title', ['u1'], 'Title')->editableUnits($units));
    }
}
