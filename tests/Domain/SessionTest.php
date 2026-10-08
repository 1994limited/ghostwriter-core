<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private const NOW = '2026-10-02T12:00:00+00:00';

    private static function now(string $modify = ''): DateTimeImmutable
    {
        $now = new DateTimeImmutable(self::NOW);

        return $modify === '' ? $now : $now->modify($modify);
    }

    public function test_a_new_piece_is_idle_with_the_formats_id_and_dates(): void
    {
        foreach (Format::cases() as $format) {
            $session = Session::start($format, 'any:journal', ['subject' => 'Bulbs'], 7, now: self::now());

            $this->assertTrue($format->isSessionId($session->id), $format->value);
            $this->assertSame(Session::IDLE, $session->status);
            $this->assertSame($format === Format::Statamic ? '7' : 7, $session->startedBy);
            $this->assertSame($format === Format::Filament ? '2026-10-02 12:00:00' : self::NOW, $session->createdAt);
        }
    }

    public function test_one_run_at_a_time(): void
    {
        $options = DomainOptions::craft();
        $session = Session::start(Format::Craft, 'guide', [], 1, now: self::now());

        $this->assertTrue($session->claim(2, $options, self::now()));
        $this->assertSame(Session::WORKING, $session->status);
        $this->assertSame(2, $session->runBy);
        $this->assertSame(2, $session->touchedBy);
        $this->assertSame(self::NOW, $session->startedWorkingAt);

        $this->assertFalse($session->claim(3, $options, self::now('+1 minute')), 'Already working.');
        $this->assertSame(2, $session->runBy);
    }

    public function test_a_claim_from_a_state_needs_that_state(): void
    {
        $options = DomainOptions::statamic();
        $session = Session::start(Format::Statamic, 'guide', [], 'a');

        $this->assertFalse($session->claim('a', $options, from: Session::FAILED));

        $session->fail('Overloaded.');
        $this->assertTrue($session->claim('b', $options, from: Session::FAILED));
        $this->assertNull($session->error);
    }

    public function test_work_that_stopped_without_saying_so_goes_stale_and_can_be_claimed(): void
    {
        $options = DomainOptions::filament(jobTimeout: 300);
        $session = Session::start(Format::Filament, 'any:posts', [], 1, now: self::now());
        $session->claim(1, $options, self::now());

        // The job limit (300 × 3 + 60) plus 120 seconds' margin.
        $this->assertSame(1080, $options->staleAfter());
        $this->assertFalse($session->isStale($options, self::now('+1079 seconds')));
        $this->assertTrue($session->isStale($options, self::now('+1081 seconds')));
        $this->assertTrue($session->claim(2, $options, self::now('+1081 seconds')), 'A stale run is claimed again.');
        $this->assertSame(2, $session->runBy);
    }

    public function test_staleness_falls_back_on_the_last_save(): void
    {
        $options = DomainOptions::craft();
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->status = Session::WORKING;
        $session->updatedAt = self::now()->format(DATE_ATOM);

        $this->assertFalse($session->recoverIfStale($options, self::now('+17 minutes')));
        $this->assertTrue($session->recoverIfStale($options, self::now('+19 minutes')));
        $this->assertSame(Session::FAILED, $session->status);
        $this->assertSame(DomainOptions::STOPPED, $session->error);
        $this->assertFalse($session->recoverIfStale($options, self::now('+1 day')), 'Only once.');
    }

    public function test_a_turn_is_retried_only_after_it_failed_on_a_persons_message(): void
    {
        $session = Session::start(Format::Statamic, 'guide', [], 'a');
        $session->addMessage('user', 'Write it.', 'a');
        $this->assertFalse($session->canRetry());

        $session->fail('Overloaded.');
        $this->assertTrue($session->canRetry());

        $session->addMessage('assistant', 'Sorry.');
        $this->assertFalse($session->canRetry());
    }

    public function test_who_is_waiting_on_ghostwriter(): void
    {
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->claim(2, DomainOptions::craft());

        $this->assertSame(2, $session->waitingOn(new Viewer(1)));
        $this->assertNull($session->waitingOn(new Viewer(2)), 'Not someone else: it is you.');

        $session->answer('Done.', null);
        $this->assertNull($session->waitingOn(new Viewer(1)));
    }

    public function test_messages_say_who_sent_them(): void
    {
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->addMessage('user', 'Hello', '4', now: self::now());
        $session->addMessage('assistant', 'Hi', extra: ['asks' => false], now: self::now());

        $this->assertSame(['role' => 'user', 'content' => 'Hello', 'at' => self::NOW, 'by' => 4], $session->messages[0]);
        $this->assertSame(['role' => 'assistant', 'content' => 'Hi', 'at' => self::NOW, 'asks' => false], $session->messages[1]);
    }

    public function test_an_answer_with_a_draft_records_what_changed(): void
    {
        $session = Session::start(Format::Statamic, 'guide', [], 'a');
        $session->claim('a', DomainOptions::statamic());

        $session->answer('Here it is.', "title: Bulbs\nbody: Plant them now.", 100, 40, self::now());

        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame("title: Bulbs\nbody: Plant them now.", $session->draft);
        $this->assertSame(['input' => 100, 'output' => 40], $session->usage);
        // The writing's words, as the draft pane counts them: not the field names.
        $this->assertSame(['change' => 'written', 'words' => 4, 'was' => null], $session->lastMessage()['draft'] ?? null);
        $this->assertFalse($session->lastMessage()['asks'] ?? null);

        $session->answer('Shorter.', "title: Bulbs\nbody: Plant.", 10, 5, self::now());
        $this->assertSame(['change' => 'updated', 'words' => 2, 'was' => 4], $session->lastMessage()['draft'] ?? null);
        $this->assertSame(['input' => 110, 'output' => 45], $session->usage);
    }

    public function test_filament_records_no_previous_word_count(): void
    {
        $session = Session::start(Format::Filament, 'any:posts', [], 1);
        $session->answer('', "title: Bulbs\nbody: Plant them now.");

        $this->assertSame(['change' => 'written', 'words' => 4], $session->lastMessage()['draft'] ?? null);
        $this->assertSame('I have updated the draft.', $session->lastMessage()['content'] ?? null);
    }

    public function test_an_answer_that_asks_is_marked_and_a_bad_draft_is_kept_with_the_problem(): void
    {
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->answer('Who is it for?', null);
        $this->assertTrue($session->lastMessage()['asks'] ?? null);
        $this->assertArrayNotHasKey('draft', $session->lastMessage() ?? []);

        $session->answer('Here.', "body: no title\n");
        $this->assertSame("body: no title\n", $session->draft, 'Kept, so nothing the model wrote is lost.');
        $this->assertStringEndsWith('(The draft needs a title. Ask me to fix it.)', (string) ($session->lastMessage()['content'] ?? ''));
    }

    public function test_the_title_comes_from_the_draft_or_the_brief(): void
    {
        $session = Session::start(Format::Craft, 'guide', ['subject' => "  A very long subject line that goes on and on and on, well past eighty characters in all\nsecond line"], 1);
        $this->assertSame('A very long subject line that goes on and on and on, well past eighty character…', $session->title());

        $statamic = Session::start(Format::Statamic, 'guide', ['subject' => '', 'reader' => "Gardeners\nand others"], 'a');
        $this->assertSame("Gardeners\nand others", $statamic->title(), 'Statamic shows the first answer whole.');

        $statamic->draft = "title: \"Bulbs in October\"\nbody: x";
        $this->assertSame('Bulbs in October', $statamic->title());
        $this->assertSame('Untitled', Session::start(Format::Filament, 'k', [], 1)->title());
    }

    public function test_applying_marks_when_and_who(): void
    {
        $session = Session::start(Format::Filament, 'any:posts', [], 1);
        $session->markApplied(3, self::now());

        $this->assertSame('2026-10-02 12:00:00', $session->appliedAt);
        $this->assertSame(3, $session->touchedBy);
    }

    public function test_filament_keeps_the_record_key_by_whether_it_edits(): void
    {
        $editing = Session::fromArray(['id' => 9, 'ulid' => '01m3ycrdns656yxamjqnhy8h9f', 'resource' => 'posts', 'record_key' => '10', 'kind' => 'k', 'editing' => 1], Format::Filament);
        $new = Session::fromArray(['id' => 8, 'ulid' => '01m3ycrdns656yxamjqnhy8h9e', 'resource' => 'posts', 'record_key' => '11', 'kind' => 'k', 'editing' => 0], Format::Filament);

        $this->assertTrue($editing->isEditing());
        $this->assertSame('10', $editing->source);
        $this->assertNull($editing->recordId);
        $this->assertFalse($new->isEditing());
        $this->assertSame('11', $new->recordId);
        $this->assertSame(8, $new->key);

        $new->recordId = '12';
        $this->assertSame('12', $new->toArray()['record_key']);
    }
}
