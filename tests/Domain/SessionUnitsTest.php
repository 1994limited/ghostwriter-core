<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comment;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\UnitsTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The draft's unit ids, stored beside it like the gaps: only once there are some. */
final class SessionUnitsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Format}>
     */
    public static function formats(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function test_units_round_trip_and_are_left_out_until_there_are_some(Format $format): void
    {
        $session = Session::start($format, 'page', ['brief' => 'Winter care']);

        $this->assertArrayNotHasKey('units', $session->toArray(), 'a store with no place for them is never sent the key');

        $session->units = Units::fromDraft(UnitsTest::draft(), UnitsTest::schema())->sidecar();
        $stored = $session->toArray();
        $back = Session::fromArray($stored, $format);

        $this->assertSame($session->units, $back->units);
        $this->assertSame($stored, $back->toArray());

        $back->units = [];
        $this->assertArrayHasKey('units', $back->toArray(), 'a record that had them can be emptied');
    }

    #[DataProvider('formats')]
    public function test_extras_round_trip_and_are_left_out_until_there_are_some(Format $format): void
    {
        $session = Session::start($format, 'page', ['brief' => 'Winter care']);

        $this->assertArrayNotHasKey('extras', $session->toArray());

        $session->extras = [['id' => 'x1', 'kind' => 'stats', 'items' => [['id' => 'x1.1', 'text' => '4 visits', 'source' => ['kind' => 'draft', 'quote' => 'Four visits']]]]];
        $stored = $session->toArray();
        $back = Session::fromArray($stored, $format);

        $this->assertSame($session->extras, $back->extras);
        $this->assertSame($stored, $back->toArray());

        $back->extras = [];
        $this->assertArrayHasKey('extras', $back->toArray());
    }

    #[DataProvider('formats')]
    public function test_the_layouts_and_the_chosen_one_round_trip_and_are_left_out_until_there_are_some(Format $format): void
    {
        $session = Session::start($format, 'page', ['brief' => 'Winter care']);

        $this->assertArrayNotHasKey('plans', $session->toArray());
        $this->assertArrayNotHasKey('plan', $session->toArray());

        $session->plans = [['id' => 'w', 'origin' => 'writer', 'name' => 'As written', 'description' => '', 'fields' => ['body' => [['type' => 'text', 'placements' => [['field' => '@body', 'from' => ['u2']]]]]]]];
        $session->plan = 'w';
        $stored = $session->toArray();
        $back = Session::fromArray($stored, $format);

        $this->assertSame([$session->plans, 'w'], [$back->plans, $back->plan]);
        $this->assertSame($stored, $back->toArray());

        $back->plan = null;
        $this->assertNull($back->toArray()['plan']);
    }

    #[DataProvider('formats')]
    public function test_comments_are_conversation_messages_with_no_store_of_their_own(Format $format): void
    {
        $session = Session::start($format, 'page', ['brief' => 'Winter care']);
        $comment = Comment::make(1, Scope::text('u8', new TextQuote('mixed borders')), 'Which ones?', 1);
        $session->addMessage('user', '1 comment on the draft', 1, [Comments::KEY => ['items' => [$comment->toArray()]]]);
        $stored = $session->toArray();
        $back = Session::fromArray($stored, $format);

        $this->assertArrayNotHasKey('review', $stored);
        $this->assertEquals([$comment], Comments::itemsOf($back->lastMessage() ?? []));
        $this->assertSame($stored, $back->toArray());
    }
}
