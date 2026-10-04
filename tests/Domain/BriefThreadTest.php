<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefStage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemorySessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The brief in the conversation (1.6): asked for, filled in, checked on a
 * card, tried again, agreed, and then the writing as before.
 */
final class BriefThreadTest extends TestCase
{
    private InMemorySessionStore $store;

    private DateTimeImmutable $now;

    private Format $format = Format::Craft;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-03T12:00:00+00:00');
        $this->store = new InMemorySessionStore($this->format, fn () => $this->now);
    }

    private function guard(): SessionGuard
    {
        return new SessionGuard($this->store, new InMemoryLock, DomainOptions::craft(), fn () => $this->now);
    }

    private function kind(): ContentKind
    {
        return ContentKind::fromArray('project', ['title' => 'Project', 'questions' => [
            ['handle' => 'client', 'label' => 'Who for?'],
            ['handle' => 'result', 'label' => 'What changed?'],
        ]]);
    }

    private function opened(int $by = 1): Session
    {
        return $this->guard()->open(Session::start($this->format, 'project', [], $by, [4, 5], $this->now), new Viewer($by));
    }

    private function find(string $id): Session
    {
        $session = $this->store->find($id);
        $this->assertNotNull($session);

        return $session;
    }

    private static function text(Brief $brief): string
    {
        return "Brief: {$brief->title}\n".implode("\n", $brief->answers);
    }

    public function test_it_asks_for_the_quick_details_and_waits(): void
    {
        $session = $this->find($this->opened()->id);

        $this->assertSame(BriefStage::Details, BriefThread::stage($session));
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame([['role' => 'assistant', 'content' => BriefThread::ASK_TEXT, 'at' => '2026-10-03T12:00:00+00:00', 'brief' => ['step' => 'ask']]], $session->messages);
        $this->assertSame('What’s it called, and what should it say? A line or two is plenty.', BriefThread::ASK_TEXT);
        $this->assertSame([0], array_keys(BriefThread::visible($session)));
        $this->assertNull(BriefThread::text($session));
        $this->assertSame(1, $session->touchedBy);
    }

    public function test_the_whole_way_from_details_to_draft(): void
    {
        $id = $this->opened()->id;
        $guard = $this->guard();
        $viewer = new Viewer(1);

        // The reply: Ghostwriter fills in the brief.
        $session = $guard->details($id, ' Kiln opening, for the trust. ', $viewer);
        $this->assertSame(BriefStage::Filling, BriefThread::stage($session));
        $this->assertTrue(BriefThread::fills($session));
        $this->assertTrue($session->isWorking());

        $request = BriefThread::request($session, $this->kind(), ['Mill']);
        $this->assertSame('Kiln opening, for the trust.', $request->details);
        $this->assertNull($request->title);
        $this->assertSame([4, 5], $request->examples);
        $this->assertSame(['Mill'], $request->titles);
        $this->assertNull($request->previous);

        // The card.
        $session = $guard->propose($id, new Brief('Kiln', ['client' => 'The trust', 'result' => '[Add: what changed]'], [4, 5]), 10, 20);
        $this->assertNotNull($session);
        $this->assertSame(BriefStage::Proposed, BriefThread::stage($session));
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame(['client' => 'The trust', 'result' => '[Add: what changed]'], $session->answers);
        $this->assertSame(['input' => 10, 'output' => 20], $session->usage);
        $this->assertSame(BriefThread::CARD_TEXT.' '.BriefThread::OPEN_TEXT, $session->messages[2]['content']);
        $this->assertSame(['step' => 'card', 'title' => 'Kiln', 'answers' => ['client' => 'The trust', 'result' => '[Add: what changed]'], 'examples' => [4, 5], 'attempt' => 1, 'agreed' => false], $session->messages[2]['brief']);
        $this->assertSame('Kiln', $session->title());
        $this->assertFalse(BriefThread::agreed($session));

        // "Try again", with one answer changed and an example unticked.
        $session = $guard->tryAgain($id, $viewer, ['client' => 'The Harbour Trust', 'result' => '[Add: what changed]'], [4]);
        $this->assertSame(BriefStage::Filling, BriefThread::stage($session));
        $this->assertSame(['role' => 'user', 'content' => 'Try again'], array_intersect_key($session->messages[3], ['role' => 1, 'content' => 1]));
        $request = BriefThread::request($session, $this->kind());
        $this->assertSame(['client'], $request->kept);
        $this->assertSame([4], $request->examples);
        $this->assertSame('The Harbour Trust', $request->previous?->answers['client']);
        $this->assertSame('Kiln', $request->title);

        $guard->propose($id, new Brief('Kiln', ['client' => 'The Harbour Trust', 'result' => 'More visitors.'], [4], 2));
        $session = $this->find($id);
        $this->assertSame(BriefThread::CARD_TEXT, $session->messages[4]['content']);
        $this->assertSame([0, 1, 4], array_keys(BriefThread::visible($session)), 'Only the latest card is shown; "Try again" is not.');

        // Agreed, with a last change: the brief is stored and the writing starts.
        $session = $guard->agree($id, $viewer, self::text(...), ['result' => 'More visitors in May.'], null, 'The new kiln');
        $this->assertSame(BriefStage::Writing, BriefThread::stage($session));
        $this->assertTrue($session->isWorking());
        $this->assertTrue(BriefThread::agreed($session));
        $this->assertSame(['client' => 'The Harbour Trust', 'result' => 'More visitors in May.'], $session->answers);
        $this->assertSame([4], $session->examples);
        $this->assertSame("Brief: The new kiln\nThe Harbour Trust\nMore visitors in May.", BriefThread::text($session));
        $this->assertSame('The new kiln', BriefThread::card($session)?->title);
        $this->assertSame([0, 1, 4], array_keys(BriefThread::visible($session)));

        // The writer starts from the agreed brief alone.
        $conversation = new Conversation($session->messages, $session->draft, $session->answers);
        $this->assertEquals([new Message('user', "Brief: The new kiln\nThe Harbour Trust\nMore visitors in May.")], $conversation->messages);

        // Questions first, then the draft.
        $guard->change($id, fn (Session $s) => $s->answer('Which month did it open?', null));
        $this->assertSame(BriefStage::Questions, BriefThread::stage($this->find($id)));

        $guard->send($id, 'Just draft it with what you have.', $viewer);
        $guard->change($id, fn (Session $s) => $s->answer('Here it is.', "title: Kiln\n"));
        $session = $this->find($id);
        $this->assertSame(BriefStage::Drafting, BriefThread::stage($session));
        $this->assertCount(4, (new Conversation($session->messages))->messages);
        $this->assertSame([0, 1, 4, 6, 7, 8], array_keys(BriefThread::visible($session)));
    }

    public function test_with_nothing_ticked_the_kinds_examples_come_first_then_the_fillers_choice(): void
    {
        $guard = $this->guard();
        $viewer = new Viewer(1);
        $id = $guard->open(Session::start($this->format, 'project', [], 1, [], $this->now), $viewer)->id;
        $session = $guard->details($id, 'Kiln opening.', $viewer);
        $candidates = [7 => 'Mill', 8 => 'Harbour'];

        $this->assertSame([7, 8], BriefThread::request($session, $this->kind(), ['Mill'], $candidates, [7, 8])->examples, "The kind's own.");
        $this->assertFalse(BriefThread::request($session, $this->kind(), ['Mill'], $candidates, [7, 8])->choosesExamples());

        $request = BriefThread::request($session, $this->kind(), ['Mill'], $candidates);
        $this->assertSame([], $request->examples);
        $this->assertSame([['id' => 7, 'title' => 'Mill'], ['id' => 8, 'title' => 'Harbour']], $request->candidates);
        $this->assertTrue($request->choosesExamples());
        $this->assertFalse(BriefThread::request($session, $this->kind(), ['Mill'])->choosesExamples(), 'Nothing to choose from.');

        // The person's ticks win.
        $ticked = $this->guard()->details($this->opened()->id, 'Kiln.', $viewer);
        $this->assertSame([4, 5], BriefThread::request($ticked, $this->kind(), [], $candidates, [7])->examples);
        $this->assertFalse(BriefThread::request($ticked, $this->kind(), [], $candidates)->choosesExamples());
    }

    public function test_try_again_keeps_the_ticks_on_the_card(): void
    {
        $guard = $this->guard();
        $viewer = new Viewer(1);
        $candidates = [7 => 'Mill', 8 => 'Harbour'];
        $id = $guard->open(Session::start($this->format, 'project', [], 1, [], $this->now), $viewer)->id;
        $guard->details($id, 'Kiln opening.', $viewer);

        // The filler chose 8; the person leaves it ticked.
        $guard->propose($id, new Brief('Kiln', ['client' => 'x', 'result' => 'y'], [8]));
        $this->assertSame([8], $this->find($id)->examples);
        $request = BriefThread::request($guard->tryAgain($id, $viewer, [], [8]), $this->kind(), [], $candidates);
        $this->assertSame([8], $request->examples);
        $this->assertFalse($request->choosesExamples());

        // They untick it: none are chosen for them.
        $guard->propose($id, new Brief('Kiln', ['client' => 'x', 'result' => 'y'], [8], 2));
        $request = BriefThread::request($guard->tryAgain($id, $viewer, [], []), $this->kind(), [], $candidates, [7]);
        $this->assertSame([], $request->examples);
        $this->assertTrue($request->examplesKept);
        $this->assertFalse($request->choosesExamples());

        // A card that had none, left as it was: the filler chooses again.
        $guard->propose($id, new Brief('Kiln', ['client' => 'x', 'result' => 'y'], [], 3));
        $request = BriefThread::request($guard->tryAgain($id, $viewer, [], []), $this->kind(), [], $candidates);
        $this->assertTrue($request->choosesExamples());
    }

    public function test_the_agreed_brief_stays_editable(): void
    {
        $id = $this->agreedPiece();

        $session = $this->guard()->editBrief($id, new Viewer(2), self::text(...), ['client' => 'Someone'], [5]);

        $this->assertSame(['client' => 'Someone', 'result' => 'More.'], $session->answers);
        $this->assertSame([5], $session->examples);
        $this->assertSame("Brief: Kiln\nSomeone\nMore.", BriefThread::text($session));
        $this->assertTrue(BriefThread::agreed($session));
        $this->assertSame(Session::IDLE, $session->status, 'No turn runs.');
        $this->assertSame(2, $session->touchedBy);
    }

    public function test_the_brief_cannot_be_edited_while_ghostwriter_writes_or_before_it_is_agreed(): void
    {
        $this->expectException(Busy::class);

        $this->guard()->editBrief($this->agreedPiece(working: true), new Viewer(1), self::text(...));
    }

    public function test_a_brief_not_yet_agreed_is_changed_on_the_card_not_with_edit_brief(): void
    {
        $id = $this->opened()->id;
        $this->expectException(Conflict::class);

        $this->guard()->editBrief($id, new Viewer(1), self::text(...));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfTurn(): iterable
    {
        yield 'details twice' => ['details'];
        yield 'agreeing before there is a card' => ['agree'];
        yield 'trying again before there is a card' => ['tryAgain'];
    }

    #[DataProvider('outOfTurn')]
    public function test_each_step_comes_in_its_turn(string $step): void
    {
        $id = $this->opened()->id;
        $guard = $this->guard();
        $viewer = new Viewer(1);

        if ($step === 'details') {
            $guard->details($id, 'x', $viewer);
            $guard->propose($id, new Brief('x', []));
        }

        $this->expectException(Conflict::class);

        match ($step) {
            'details' => $guard->details($id, 'again', $viewer),
            'agree' => $guard->agree($id, $viewer, self::text(...)),
            default => $guard->tryAgain($id, $viewer),
        };
    }

    public function test_nothing_is_asked_twice_while_the_brief_is_being_filled(): void
    {
        $id = $this->opened()->id;
        $this->guard()->details($id, 'x', new Viewer(1));

        $this->expectException(Busy::class);

        $this->guard()->details($id, 'y', new Viewer(2));
    }

    public function test_a_brief_that_failed_can_be_filled_again(): void
    {
        $id = $this->opened()->id;
        $guard = $this->guard();
        $guard->details($id, 'x', new Viewer(1));
        $guard->change($id, fn (Session $s) => $s->fail('Ghostwriter could not fill in the brief.'));

        $session = $guard->retry($id, new Viewer(1));

        $this->assertTrue($session->isWorking());
        $this->assertTrue(BriefThread::fills($session));
    }

    public function test_a_late_brief_is_not_laid_over_a_piece_that_moved_on(): void
    {
        $id = $this->opened()->id;

        $this->guard()->propose($id, new Brief('x', ['client' => 'y']));

        $this->assertCount(1, $this->find($id)->messages);
    }

    public function test_draft_this_starts_filling_from_the_idea_at_once(): void
    {
        $session = $this->guard()->openFromIdea(Session::start($this->format, 'project', [], 1, [9], $this->now), new Viewer(1), ' Kiln opening ', "Nothing on the kiln.\n\nStart with May.");

        $this->assertSame(BriefStage::Filling, BriefThread::stage($session));
        $this->assertTrue($session->isWorking());
        $this->assertSame([], BriefThread::visible($session));

        $request = BriefThread::request($session, $this->kind());
        $this->assertSame('Kiln opening', $request->title);
        $this->assertSame("Nothing on the kiln.\n\nStart with May.", $request->details);
        $this->assertSame([9], $request->examples);

        $this->guard()->propose($session->id, new Brief('Kiln opening', ['client' => 'x', 'result' => 'y'], [9]));
        $this->assertSame([1], array_keys(BriefThread::visible($this->find($session->id))));
    }

    public function test_pieces_from_the_brief_screen_and_edits_are_past_the_brief(): void
    {
        $session = $this->guard()->start(Session::start($this->format, 'project', ['client' => 'x'], 1), 'The brief.', new Viewer(1));

        $this->assertSame(BriefStage::Writing, BriefThread::stage($session));
        $this->assertSame('The brief.', BriefThread::text($session));
        $this->assertNull(BriefThread::card($session));
        $this->assertSame([], BriefThread::visible($session));
        $this->assertSame('x', $session->title());

        $editing = new Session($this->format, 'e', 'project', messages: [['role' => 'user', 'content' => 'Make it shorter.', 'editing' => true]], source: 3);
        $this->assertNull(BriefThread::text($editing));
        $this->assertSame([0], array_keys(BriefThread::visible($editing)));
        $this->assertSame(BriefStage::Writing, BriefThread::stage($editing));
    }

    /**
     * @return iterable<string, array{Format}>
     */
    public static function formats(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function test_the_thread_is_stored_in_the_messages_and_reads_back_in_every_format(Format $format): void
    {
        $this->format = $format;
        $this->store = new InMemorySessionStore($format, fn () => $this->now);
        $id = $this->agreedPiece();

        $session = Session::fromArray($this->find($id)->toArray(), $format);

        $this->assertSame(BriefStage::Drafting, BriefThread::stage($session));
        $this->assertSame('Kiln', BriefThread::card($session)?->title);
        $this->assertTrue(BriefThread::agreed($session));
    }

    public function test_the_messages_core_writes_are_the_english_source_strings(): void
    {
        $strings = require dirname(__DIR__, 2).'/resources/lang/en/brief.php';

        $this->assertSame(BriefThread::ASK_TEXT, $strings['ask']);
        $this->assertSame(BriefThread::CARD_TEXT, $strings['card']);
        $this->assertSame(BriefThread::OPEN_TEXT, $strings['card.open']);
        $this->assertSame(BriefThread::TRY_AGAIN_TEXT, $strings['try-again']);
        $this->assertSame('Looks right, start writing', $strings['agree']);

        foreach ($strings as $key => $text) {
            $this->assertStringNotContainsStringIgnoringCase('guess', $text, $key);
        }
    }

    private function agreedPiece(bool $working = false): string
    {
        $id = $this->opened()->id;
        $guard = $this->guard();
        $guard->details($id, 'Kiln', new Viewer(1));
        $guard->propose($id, new Brief('Kiln', ['client' => 'The trust', 'result' => 'More.'], [4]));
        $guard->agree($id, new Viewer(1), self::text(...));

        if (! $working) {
            $guard->change($id, fn (Session $s) => $s->answer('Here it is.', "title: Kiln\n"));
        }

        return $id;
    }
}
