<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comment;
use NineteenNinetyFour\Ghostwriter\Core\Review\CommentOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Review\CommentResult;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Comments as conversation messages: what they store, how their pins are
 * placed in each layout, and what happens to them as the draft moves on.
 * No model, except where a run is answered.
 */
final class CommentsTest extends ReviewTestCase
{
    public function test_the_messages_round_trip_through_every_store_format(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>"));
        $this->send($session, [[Scope::text('u8', new TextQuote('Gardens with mixed borders.', 'Who it suits ', ''), 'Who it suits', 'w', 'page_builder/1'), 'Warmer.'], [Scope::block(['u10'], 'Button'), 'Friendlier.']]);
        $this->comments->revise($session->id, $this->conversation($this->fresh($session)), $this->writer(), $this->site());
        $fresh = $this->fresh($session);

        foreach (Format::cases() as $format) {
            $copy = Session::fromArray($fresh->toArray($format), $format);
            $this->assertEquals($this->comments->pins($fresh), $this->comments->pins($copy), $format->value);
        }

        $item = Comment::fromArray($fresh->messages[count($fresh->messages) - 2][Comments::KEY]['items'][0]);
        $this->assertSame(['Gardens with mixed borders.', 'Who it suits ', '1'], [$item?->scope->quote?->exact, $item?->scope->quote?->prefix, $item?->by]);
        $result = CommentResult::fromArray(['number' => 2, 'id' => 'x', 'outcome' => 'changed', 'reply' => 'Done.', 'resolved' => ['by' => '2', 'at' => '2026-10-04T10:00:00+00:00']]);
        $this->assertSame([CommentOutcome::Changed, ['by' => '2', 'at' => '2026-10-04T10:00:00+00:00']], [$result?->outcome, $result?->resolved]);
        $this->assertNull(CommentResult::fromArray(['number' => 1, 'outcome' => 'eaten']));
    }

    public function test_pins_follow_their_words_into_another_layout(): void
    {
        $session = $this->drafted();
        $this->send($session, [[Scope::block(['u7'], 'The visits', 'w', 'page_builder/1'), 'Shorter.'], [Scope::block(['u9', 'u10'], 'Call to action'), 'Warmer.']]);

        $this->assertSame(['page_builder/1'], $this->pin($session, 1)['blocks'], 'the writer\'s layout: the text block');
        $scannable = $this->pin($session, 1, 'p1')['blocks'];
        $this->assertContains('page_builder/2', $scannable, 'Scannable: the visits section');
        $this->assertSame(['page_builder/4'], $this->pin($session, 2, 'p1')['blocks']);
        $this->assertTrue($this->pin($session, 2, 'p1')['inLayout']);
    }

    public function test_a_comment_whose_words_a_chat_turn_removed_is_detached(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Friendlier.\n  units:\n    u10: Book yours\n</changes>"));
        $this->send($session, [[Scope::block(['u10'], 'Button'), 'Friendlier.']]);
        $this->comments->revise($session->id, $this->conversation($this->fresh($session)), $this->writer(), $this->site());
        $this->assertFalse($this->pin($session, 1)['detached']);

        // A later turn drops the call to action altogether.
        $this->guard->change($session->id, function (Session $session) {
            $data = Draft::parse((string) $session->draft)->data;
            $data['page_builder'] = array_values(array_filter($data['page_builder'], fn (array $block) => $block['type'] !== 'cta'));
            $before = $session->draft;
            $session->draft = self::yaml($data);
            $this->layouts->afterEdit($session, $before, $this->site());
        });

        $pin = $this->pin($session, 1);
        $this->assertSame(['detached', 'Detached', true, []], [$pin['status'], $pin['state'], $pin['detached'], $pin['blocks']]);
    }

    public function test_resolving_is_allowed_while_ghostwriter_works_and_needs_an_answer(): void
    {
        $session = $this->drafted();
        $this->fake->respond('reviser', self::reply("<changes>\n- comment: 1\n  reply: Left it.\n</changes>"));
        $this->send($session, [[Scope::page(), 'Is the tone right?']]);
        $this->comments->revise($session->id, $this->conversation($this->fresh($session)), $this->writer(), $this->site());
        $answer = $this->pin($session, 1)['answer'];

        // Another run starts; resolving the first comment still goes through.
        $this->send($session, [[Scope::page(), 'Shorter.']], $this->priya);
        $this->assertSame('sending', $this->pin($session, 2)['status']);
        $this->comments->resolve($session->id, $this->daniel, $answer, 1);
        $this->assertSame('resolved', $this->pin($session, 1)['status']);
        $this->assertTrue($this->fresh($session)->isWorking(), 'the run carries on');
    }
}
