<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionImages;
use PHPUnit\Framework\TestCase;

final class SessionImagesTest extends TestCase
{
    private static function session(): Session
    {
        return Session::start(Format::Statamic, 'guide', [], 'a');
    }

    public function test_a_picture_is_made_once_at_a_time(): void
    {
        $session = self::session();
        $this->assertSame(SessionImages::EMPTY, SessionImages::status($session, 'hero'));

        SessionImages::startMaking($session, 'hero');
        $this->assertSame(['status' => 'working', 'error' => null], $session->images['hero']);

        try {
            SessionImages::startMaking($session, 'hero');
            $this->fail('Conflict');
        } catch (Conflict $conflict) {
            $this->assertSame('That image is already being made.', $conflict->getMessage());
        }

        SessionImages::made($session, 'hero', ['path' => 'a.png', 'url' => '/a.png']);
        $this->assertSame('done', SessionImages::status($session, 'hero'));
        $this->assertSame('a.png', $session->images['hero']['path']);

        SessionImages::failed($session, 'thumb', 'No key.');
        $this->assertSame(['status' => 'failed', 'error' => 'No key.'], $session->images['thumb']);
    }

    public function test_a_chosen_photo_keeps_what_was_offered(): void
    {
        $session = self::session();
        SessionImages::offer($session, 'hero', ['query' => 'tulips', 'options' => [['id' => '1']], 'judged' => true, 'stray' => 1]);
        SessionImages::choose($session, 'hero', 'journal/t.jpg', '/assets/journal/t.jpg', 'Ann via Unsplash');

        $this->assertSame(['status' => 'done', 'path' => 'journal/t.jpg', 'url' => '/assets/journal/t.jpg', 'error' => null, 'credit' => 'Ann via Unsplash', 'query' => 'tulips', 'options' => [['id' => '1']], 'judged' => true], $session->images['hero']);
    }

    public function test_an_image_is_copied_only_from_a_field_that_has_one(): void
    {
        $session = self::session();
        SessionImages::choose($session, 'hero', 'h.jpg', '/h.jpg', 'X');
        SessionImages::offer($session, 'thumb', ['query' => 'q']);
        SessionImages::copy($session, 'thumb', 'hero');

        $this->assertSame(['status' => 'done', 'error' => null, 'path' => 'h.jpg', 'url' => '/h.jpg', 'credit' => 'X', 'query' => 'q'], $session->images['thumb']);

        $this->expectException(Conflict::class);
        SessionImages::copy($session, 'hero', 'nothing');
    }

    public function test_a_turn_does_not_undo_a_choice_made_while_it_ran(): void
    {
        $before = ['hero' => ['status' => 'empty'], 'thumb' => ['status' => 'empty'], 'old' => ['status' => 'done']];
        $turn = ['hero' => ['status' => 'done', 'path' => 'turn.jpg'], 'thumb' => ['status' => 'done', 'path' => 'turn-t.jpg']];

        $latest = self::session();
        $latest->images = $before;
        $latest->images['hero'] = ['status' => 'done', 'path' => 'person.jpg'];

        SessionImages::mergeTurn($latest, $turn, $before);

        $this->assertSame('person.jpg', $latest->images['hero']['path'], 'The person chose meanwhile: kept.');
        $this->assertSame('turn-t.jpg', $latest->images['thumb']['path'], 'Untouched: the turn’s.');
        $this->assertArrayNotHasKey('old', $latest->images, 'Removed by the turn.');
    }
}
