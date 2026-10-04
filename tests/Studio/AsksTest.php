<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemorySessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Studio\AskedQuestion;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Asks;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use PHPUnit\Framework\TestCase;

final class AsksTest extends TestCase
{
    private const REPLY = <<<'TEXT'
<reply>
I can write around the process; a few things only you know.
</reply>
<questions>
- id: project
  question: Which real project should this be about?
  hint: Client, place and what you did
- id: phasing
  question: How do you phase larger projects?
  kind: choice
  options: [All at once, In stages]
  optional: true
- question: What usually drives labour on your jobs?
</questions>
TEXT;

    public function test_the_questions_block_is_kept_apart_from_the_reply(): void
    {
        $response = TaggedResponse::parse(self::REPLY, 'draft');

        $this->assertSame('I can write around the process; a few things only you know.', $response->reply);
        $this->assertNull($response->document);
        $this->assertStringStartsWith('- id: project', (string) $response->questions);
    }

    public function test_questions_are_read_with_the_reply_as_intro(): void
    {
        $response = TaggedResponse::parse(self::REPLY, 'draft');
        $asks = Asks::read($response->questions, $response->reply);

        $this->assertNotNull($asks);
        $this->assertSame('I can write around the process; a few things only you know.', $asks->intro);
        $this->assertSame(['project', 'phasing', 'q3'], array_map(fn (AskedQuestion $q) => $q->id, $asks->questions));
        $this->assertSame('Client, place and what you did', $asks->questions[0]->hint);
        $this->assertSame(AskedQuestion::CHOICE, $asks->questions[1]->kind);
        $this->assertSame(['All at once', 'In stages'], $asks->questions[1]->options);
        $this->assertTrue($asks->questions[1]->optional);
        $this->assertFalse($asks->questions[0]->optional);
        $this->assertSame(AskedQuestion::TEXT, $asks->questions[2]->kind);
    }

    public function test_at_most_four_questions_and_ids_are_made_safe_and_unique(): void
    {
        $asks = Asks::read("- {id: 'A b', question: One?}\n- {id: a-b, question: Two?}\n- Three?\n- {question: ''}\n- Four?\n- Five?");

        $this->assertNotNull($asks);
        $this->assertSame(['a-b', 'q2', 'q3', 'q4'], array_map(fn (AskedQuestion $q) => $q->id, $asks->questions));
        $this->assertSame(['One?', 'Two?', 'Three?', 'Four?'], array_map(fn (AskedQuestion $q) => $q->question, $asks->questions));
    }

    public function test_a_choice_without_two_options_is_a_text_question(): void
    {
        $asks = Asks::read("- id: x\n  question: Which?\n  kind: choice\n  options: [Only one]");

        $this->assertSame(AskedQuestion::TEXT, $asks?->questions[0]->kind);
        $this->assertSame([], $asks?->questions[0]->options);
    }

    public function test_a_fenced_block_or_one_with_an_intro_is_read(): void
    {
        $this->assertSame('Why?', Asks::read("```yaml\n- question: Why?\n```")?->questions[0]->question);

        $asks = Asks::read("intro: Two things.\nquestions:\n  - question: Who?");
        $this->assertSame('Two things.', $asks?->intro);
    }

    public function test_nothing_readable_is_null(): void
    {
        $this->assertNull(Asks::read(null));
        $this->assertNull(Asks::read('  '));
        $this->assertNull(Asks::read('Just some prose? With questions?'));
        $this->assertNull(Asks::read("- [unclosed\n  : :"));
        $this->assertNull(Asks::read('- {hint: no question}'));
    }

    public function test_the_session_keeps_them_and_the_content_reads_as_before(): void
    {
        $response = TaggedResponse::parse(self::REPLY, 'draft');
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->answer($response->reply, $response->document, questions: $response->questions);
        $last = $session->lastMessage() ?? [];

        $this->assertTrue($last['asks']);
        $this->assertSame("I can write around the process; a few things only you know.\n\n1. Which real project should this be about?\n2. How do you phase larger projects?\n3. What usually drives labour on your jobs?", $last['content']);
        $this->assertSame('project', $last['asked']['questions'][0]['id']);
        $this->assertSame(Asks::read($response->questions, $response->reply)?->toArray(), Asks::fromMessage($last)?->toArray(), 'Round trip.');
    }

    public function test_an_unreadable_block_is_kept_in_the_reply(): void
    {
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->answer('A few things first.', null, questions: 'Who was the client? When did it finish?');
        $last = $session->lastMessage() ?? [];

        $this->assertSame("A few things first.\n\nWho was the client? When did it finish?", $last['content']);
        $this->assertTrue($last['asks']);
        $this->assertArrayNotHasKey('asked', $last);
    }

    public function test_questions_beside_a_draft_are_ignored(): void
    {
        $session = Session::start(Format::Craft, 'guide', [], 1);
        $session->answer('Here it is.', 'title: A', questions: '- question: Who?');

        $this->assertArrayNotHasKey('asked', $session->lastMessage() ?? []);
        $this->assertFalse($session->lastMessage()['asks'] ?? null);
    }

    public function test_an_older_message_has_no_questions(): void
    {
        $this->assertNull(Asks::fromMessage(['role' => 'assistant', 'content' => '1. Who? 2. When?', 'asks' => true]));
        $this->assertNull(Asks::present(['role' => 'assistant', 'content' => 'Who?']));
        $this->assertFalse(Asks::isAnswers(['role' => 'user', 'content' => 'The client was X.']));
    }

    public function test_the_answers_go_back_as_question_and_answer_pairs(): void
    {
        $asks = Asks::read(TaggedResponse::parse(self::REPLY, 'draft')->questions, 'Intro.');
        $this->assertNotNull($asks);

        $reply = $asks->reply(['project' => ' The Mill House ', 'phasing' => 'In stages', 'q3' => ''], 'Keep it short.');

        $this->assertSame(
            "Which real project should this be about? → The Mill House\n\nHow do you phase larger projects? → In stages\n\nWhat usually drives labour on your jobs? → skipped\n\nAlso: Keep it short.",
            $reply['content'],
        );
        $this->assertSame([
            ['id' => 'project', 'question' => 'Which real project should this be about?', 'answer' => 'The Mill House'],
            ['id' => 'phasing', 'question' => 'How do you phase larger projects?', 'answer' => 'In stages'],
            ['id' => 'q3', 'question' => 'What usually drives labour on your jobs?', 'answer' => null],
        ], $reply['extra']['answers']);
        $this->assertSame('Keep it short.', $reply['extra']['more']);

        $this->assertArrayNotHasKey('more', $asks->reply(['project' => 'X'])['extra']);
        $this->assertTrue($asks->unanswered(['project' => '  ']));
        $this->assertFalse($asks->unanswered([], 'Something.'));
    }

    public function test_a_panel_sees_each_question_with_its_answer(): void
    {
        $asks = Asks::read(TaggedResponse::parse(self::REPLY, 'draft')->questions, 'Intro.');
        $question = ['role' => 'assistant', 'content' => $asks?->text(), Asks::KEY => $asks?->toArray()];

        $waiting = Asks::present($question);
        $this->assertFalse($waiting['answered'] ?? null);
        $this->assertSame([null, null, null], array_column($waiting['questions'] ?? [], 'answer'));

        $answers = ['role' => 'user', 'content' => '…'] + ($asks?->reply(['project' => 'The Mill House'])['extra'] ?? []);
        $this->assertTrue(Asks::isAnswers($answers));

        $answered = Asks::present($question, $answers);
        $this->assertTrue($answered['answered'] ?? null);
        $this->assertSame(['The Mill House', null, null], array_column($answered['questions'] ?? [], 'answer'));
        $this->assertSame('Intro.', $answered['intro'] ?? null);

        // "Just draft it" instead: still read-only, nothing answered.
        $this->assertFalse(Asks::present($question, ['role' => 'user', 'content' => 'Just draft it.'])['answered'] ?? null);
    }

    public function test_the_writer_sees_the_questions_and_the_answers_as_text(): void
    {
        $asks = Asks::read(TaggedResponse::parse(self::REPLY, 'draft')->questions, 'Intro.');
        $reply = $asks?->reply(['project' => 'The Mill House']) ?? ['content' => '', 'extra' => []];
        $conversation = new Conversation([
            ['role' => 'user', 'content' => 'The brief.'],
            ['role' => 'assistant', 'content' => $asks?->text(), Asks::KEY => $asks?->toArray()],
            ['role' => 'user', 'content' => $reply['content']] + $reply['extra'],
        ]);

        $this->assertStringContainsString('1. Which real project should this be about?', $conversation->messages[1]->content);
        $this->assertStringContainsString('Which real project should this be about? → The Mill House', $conversation->messages[2]->content);
        $this->assertStringContainsString('→ skipped', $conversation->messages[2]->content);
    }

    public function test_the_guard_sends_the_answers_as_one_message(): void
    {
        $now = new DateTimeImmutable('2026-10-04T12:00:00+00:00');
        $store = new InMemorySessionStore(Format::Craft, fn () => $now);
        $guard = new SessionGuard($store, new InMemoryLock, DomainOptions::craft(), fn () => $now);
        $session = $guard->start(Session::start(Format::Craft, 'guide', [], 1), 'The brief.', new Viewer(1));

        $session = $guard->change($session->id, function (Session $session) {
            $response = TaggedResponse::parse(self::REPLY, 'draft');
            $session->answer($response->reply, $response->document, questions: $response->questions);
        });
        $this->assertNotNull($session);

        try {
            $guard->answerQuestions($session->id, ['project' => ' '], '', new Viewer(1));
            $this->fail('Nothing answered');
        } catch (Conflict) {
        }

        $sent = $guard->answerQuestions($session->id, ['project' => 'The Mill House'], '', new Viewer(2), skipped: 'not known');

        $this->assertSame(Session::WORKING, $sent->status);
        $last = $sent->lastMessage() ?? [];
        $this->assertSame('user', $last['role']);
        $this->assertSame(2, $last['by']);
        $this->assertStringContainsString('→ The Mill House', $last['content']);
        $this->assertStringContainsString('→ not known', $last['content']);
        $this->assertCount(3, $last['answers']);

        $guard->change($session->id, fn (Session $session) => $session->answer('Here.', 'title: A'));

        $this->expectException(Conflict::class);
        $guard->answerQuestions($session->id, ['project' => 'Again'], '', new Viewer(1));
    }
}
