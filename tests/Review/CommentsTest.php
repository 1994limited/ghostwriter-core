<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Review\Review;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Review\SessionReview;
use NineteenNinetyFour\Ghostwriter\Core\Review\ThreadStatus;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;

/**
 * Comments on a session through SessionReview: shared, locked, allowed
 * while Ghostwriter works, following their text from layout to layout and
 * from turn to turn. None of it calls a model.
 */
final class CommentsTest extends ReviewTestCase
{
    public function test_comments_are_shared_and_listed_with_their_states(): void
    {
        $session = $this->drafted();
        $one = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'Make each visit one short line.');
        $two = $this->comments->add($session->id, $this->priya, Scope::text('u8', new TextQuote('Gardens with mixed borders.'), 'Who it suits', 'w', 'page_builder/1'), 'Who is it not for?');
        $this->comments->resolve($session->id, $this->priya, $one->id);

        $threads = $this->comments->threads($this->fresh($session));

        $this->assertSame([1, 2], array_column($threads, 'number'));
        $this->assertSame(['Resolved', 'Not sent'], array_column($threads, 'state'));
        $this->assertSame(['resolved', 'open'], array_column($threads, 'status'));
        $this->assertSame([['page_builder/1'], ['page_builder/1']], array_column($threads, 'blocks'));
        $this->assertSame('Who is it not for?', $threads[1]['notes'][0]['body']);
        $this->assertSame('2', $threads[1]['startedBy']);
        $this->assertSame(3, $this->comments->review($this->fresh($session))->version);
        $this->assertSame('2', $this->fresh($session)->touchedBy);
        $this->fake->assertNothingSent();
    }

    public function test_comments_follow_their_text_into_another_layout(): void
    {
        $session = $this->drafted();
        $visits = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'One line each.');
        $hero = $this->comments->add($session->id, $this->daniel, Scope::block(['u3', 'u4', 'u5'], 'Hero', 'w', 'page_builder/0'), 'A warmer heading.');
        $text = $this->comments->add($session->id, $this->daniel, Scope::block(['u6', 'u7', 'u8'], 'Text', 'w', 'page_builder/1'), 'Shorter overall.');
        $page = $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');

        $this->guard->annotate($session->id, $this->daniel, fn (Session $session) => $this->layouts->choose($session, 'p1'));
        $session = $this->fresh($session);
        $where = $this->comments->where($session);

        $this->assertSame(['page_builder/2', 'page_builder/2/children/0', 'page_builder/2/children/1'], $where[$visits->id], 'the visits are cards in Scannable');
        $this->assertSame(['page_builder/0'], $where[$hero->id]);
        $this->assertSame(['page_builder/1', 'page_builder/2', 'page_builder/2/children/0', 'page_builder/2/children/1', 'page_builder/3'], $where[$text->id], 'one block in the writer\'s layout spans three here');
        $this->assertSame([], $where[$page->id]);
        $this->assertSame(['page_builder/1'], $this->comments->where($session, 'w')[$visits->id], 'and back in the writer\'s');
        $this->fake->assertNothingSent();
    }

    public function test_a_comment_whose_text_a_layout_does_not_use_is_not_in_that_layout(): void
    {
        $session = $this->drafted();
        $this->guard->annotate($session->id, $this->daniel, function (Session $session) {
            $session->extras = Northfold::extras()->toArray();
        });
        $stat = $this->comments->add($session->id, $this->daniel, Scope::block(['x1.1'], 'Stats', 'p1', 'page_builder/1'), 'Is it four?');

        $threads = $this->comments->threads($this->fresh($session));

        $this->assertSame([], $threads[0]['blocks']);
        $this->assertFalse($threads[0]['inLayout']);
        $this->assertSame(ThreadStatus::Open->value, $threads[0]['status'], 'not in this layout is not detached');
        $this->assertSame($stat->id, $threads[0]['id']);
    }

    public function test_after_a_chat_turn_comments_keep_their_reworded_text_and_detach_from_text_that_is_gone(): void
    {
        $session = $this->drafted();
        $visits = $this->comments->add($session->id, $this->daniel, Scope::block(['u7'], 'The visits'), 'One line each.');
        $suits = $this->comments->add($session->id, $this->daniel, Scope::block(['u8'], 'Who it suits'), 'Who is it not for?');
        $quote = $this->comments->add($session->id, $this->daniel, Scope::text('u8', new TextQuote('Gardens with mixed borders.')), 'Name some.');

        $draft = Northfold::blocksDraft();
        // The visits reworded a little; who it suits replaced outright.
        $draft['page_builder'][1]['body'] = str_replace(
            ['Prune the shrubs that need it.', "## Who it suits\n\nGardens with mixed borders. [Talk to us](#gw-link:contact-page)\n\n- Lawns\n- Gravel\n\n> We used to clear everything in October."],
            ['Prune the shrubs that need pruning.', '## Prices'."\n\nEach visit costs £60."],
            Northfold::BODY,
        );
        $this->guard->change($session->id, function (Session $session) use ($draft) {
            $before = $session->draft;
            $session->draft = self::yaml($draft);
            $this->layouts->afterEdit($session, $before, $this->site());
        });

        $review = $this->comments->review($this->fresh($session));
        $this->assertSame(ThreadStatus::Open, $review->find($visits->id)->status, 'u7 carried over (UnitMatcher)');
        $this->assertSame(['u7'], $review->find($visits->id)->scope->units);
        $this->assertSame(ThreadStatus::Detached, $review->find($suits->id)->status);
        $this->assertSame(ThreadStatus::Detached, $review->find($quote->id)->status);
        $this->assertSame('Detached', $this->comments->threads($this->fresh($session))[1]['state']);

        // "Pin to a block": the next click anchors it again, to go with the next Apply.
        $this->comments->repin($session->id, $this->daniel, $suits->id, Scope::block(['u8'], 'Prices'));
        $this->assertSame(ThreadStatus::Open, $this->comments->review($this->fresh($session))->find($suits->id)->status);
        $this->fake->assertNothingSent();
    }

    public function test_comments_may_be_added_while_ghostwriter_works(): void
    {
        $session = $this->drafted();
        $this->guard->send($session->id, 'Make it shorter.', $this->priya);

        $thread = $this->comments->add($session->id, $this->daniel, Scope::page(), 'And warmer.');

        $this->assertTrue($this->fresh($session)->isWorking());
        $this->assertSame(ThreadStatus::Open, $thread->status);
    }

    public function test_a_change_from_an_old_copy_is_refused(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.', version: 0);
        $this->comments->reply($session->id, $this->priya, $thread->id, 'Agreed.', version: 1);

        $this->expectException(Conflict::class);
        $this->comments->resolve($session->id, $this->daniel, $thread->id, version: 1);
    }

    public function test_only_the_author_edits_and_the_author_or_a_manager_deletes(): void
    {
        $session = $this->drafted();
        $thread = $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');

        try {
            $this->comments->edit($session->id, $this->priya, $thread->id, 'Colder.');
            $this->fail('Priya changed Daniel\'s comment.');
        } catch (NotAllowed) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->comments->delete($session->id, $this->priya, $thread->id);
            $this->fail('Priya deleted Daniel\'s comment.');
        } catch (NotAllowed) {
            $this->addToAssertionCount(1);
        }

        $this->comments->edit($session->id, $this->daniel, $thread->id, 'Much warmer.');
        $this->assertSame('Much warmer.', $this->comments->review($this->fresh($session))->find($thread->id)->comment()->body);

        $this->comments->delete($session->id, new Viewer('2', manager: true), $thread->id);
        $this->assertSame([], $this->comments->review($this->fresh($session))->all());
    }

    public function test_someone_who_cannot_see_the_piece_cannot_comment(): void
    {
        $session = $this->drafted();
        $private = new SessionReview(new SessionGuard($this->store, $this->lock, DomainOptions::statamic(shared: false)));

        $this->expectException(NotAllowed::class);
        $private->add($session->id, $this->priya, Scope::page(), 'Warmer.');
    }

    public function test_there_is_nothing_to_comment_on_before_a_draft(): void
    {
        $session = Session::start(Format::Statamic, 'service', [], '1');
        $this->store->save($session);

        $this->expectException(Conflict::class);
        $this->comments->add($session->id, $this->daniel, Scope::page(), 'Warmer.');
    }

    public function test_the_review_round_trips_on_the_session(): void
    {
        $session = $this->drafted();
        $this->comments->add($session->id, $this->daniel, Scope::text('u8', new TextQuote('mixed borders', 'Gardens with ', '.')), 'Which?');
        $stored = $this->fresh($session);

        $this->assertSame(Review::fromArray($stored->review)->toArray(), $stored->review);
        $this->assertSame($stored->toArray(), Session::fromArray($stored->toArray(), $stored->format)->toArray());
    }
}
