<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemorySessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Studio\StudioTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A Northfold services page drafted through FakeProvider (the writer, then
 * the layout planner), stored in memory behind a SessionGuard, with Daniel
 * (who started it) and Priya on it.
 */
abstract class ReviewTestCase extends StudioTestCase
{
    protected const BRIEF = 'Winter care: four visits between November and February. Visits are £60 each.';

    protected const PLANS = <<<'YAML'
        <plans>
        - name: Scannable
          description: The visits as cards, then who it suits
          page_builder:
            - type: hero
              place: { heading: u3, subheading: u4, image: u5 }
            - type: text
              place: { body: u6 }
            - type: section
              place: { heading: "u7#1" }
              children:
                - { type: card, place: { heading: "u7#2:lead", body: "u7#2:rest" } }
                - { type: card, place: { heading: "u7#3:lead", body: "u7#3:rest" } }
            - type: text
              place: { body: u8 }
            - type: cta
              place: { heading: u9, button: u10 }
        </plans>
        YAML;

    protected InMemorySessionStore $store;

    protected InMemoryLock $lock;

    protected SessionGuard $guard;

    protected SessionLayouts $layouts;

    protected Comments $comments;

    protected Viewer $daniel;

    protected Viewer $priya;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new DateTimeImmutable('2026-10-04T10:00:00+00:00');
        $this->store = new InMemorySessionStore(Format::Statamic, fn () => $now);
        $this->lock = new InMemoryLock;
        $this->guard = new SessionGuard($this->store, $this->lock, DomainOptions::statamic(), fn () => $now);
        $this->layouts = new SessionLayouts($this->studio(), new Layouts, $this->logger());
        $this->comments = $this->comments();
        $this->daniel = new Viewer('1');
        $this->priya = new Viewer('2');
    }

    protected function comments(): Comments
    {
        return new Comments($this->guard, $this->studio(), $this->layouts, new Layouts, $this->logger());
    }

    protected function site(): LayoutContext
    {
        return new LayoutContext(Northfold::blocks(), null, [new EntryData(['title' => 'Lawn care', 'page_builder' => [['type' => 'hero'], ['type' => 'text'], ['type' => 'cta']]], 7)]);
    }

    protected function writer(): WriterContext
    {
        return new WriterContext(new ContentKind('service', 'Service'), 'Plain and warm.', Layout::fromSchema(Northfold::blocks()), '');
    }

    protected function conversation(Session $session): Conversation
    {
        return new Conversation($session->messages, $session->draft, $session->answers);
    }

    /**
     * @param  array<string, mixed>|null  $draft
     */
    protected static function yaml(?array $draft = null): string
    {
        return Yaml::dump($draft ?? Northfold::blocksDraft(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * A first draft (writer, then planner), stored, and the fake reset.
     */
    protected function drafted(bool $plans = true): Session
    {
        $session = Session::start(Format::Statamic, 'service', ['brief' => self::BRIEF], '1');
        $this->guard->start($session, self::BRIEF, $this->daniel);
        $this->fake->respond('writer', self::reply("<reply>Here is a first draft.</reply>\n<draft>\n".self::yaml().'</draft>'));
        $this->fake->respond('layout-planner', self::reply($plans ? self::PLANS : '<plans>[]</plans>'));

        $conversation = $this->conversation($session);
        $response = $this->studio()->write($conversation, $this->writer());

        $session = $this->guard->change($session->id, function (Session $session) use ($response, $conversation) {
            $before = $session->draft;
            $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
            $this->layouts->afterWriter($session, $before, $response, $conversation, $this->writer(), $this->site());
        });

        $this->assertNotNull($session);
        $this->fake->reset();

        return $session;
    }

    protected function fresh(Session $session): Session
    {
        $found = $this->store->find($session->id);
        $this->assertNotNull($found);

        return $found;
    }

    /**
     * Sends comments as one message ([scope, words] each) and returns the session.
     *
     * @param  list<array{0: Scope, 1: string}>  $comments
     */
    protected function send(Session $session, array $comments, ?Viewer $viewer = null): Session
    {
        return $this->comments->apply($session->id, $viewer ?? $this->daniel, array_map(fn (array $comment) => ['scope' => $comment[0], 'body' => $comment[1]], $comments));
    }

    /**
     * A sent comment's pin, by number.
     *
     * @return array<string, mixed>
     */
    protected function pin(Session $session, int $number, ?string $plan = null): array
    {
        foreach ($this->comments->pins($this->fresh($session), $plan) as $pin) {
            if ($pin['number'] === $number) {
                return $pin;
            }
        }

        $this->fail("No comment {$number}.");
    }
}
