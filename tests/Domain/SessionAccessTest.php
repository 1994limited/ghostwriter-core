<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionAccess;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{DomainOptions}>
     */
    public static function presets(): iterable
    {
        yield 'statamic' => [DomainOptions::statamic()];
        yield 'craft' => [DomainOptions::craft()];
        yield 'filament' => [DomainOptions::filament()];
    }

    private static function piece(DomainOptions $options, int|string|null $startedBy = 1): Session
    {
        return Session::start($options->format, 'any:journal', [], $startedBy);
    }

    #[DataProvider('presets')]
    public function test_shared_everyone_sees_and_carries_on_every_piece(DomainOptions $options): void
    {
        $access = new SessionAccess($options);
        $piece = self::piece($options);

        $this->assertTrue($access->canSee($piece, new Viewer(1)));
        $this->assertTrue($access->canSee($piece, new Viewer(2)));
        $this->assertTrue($access->canResume($piece, new Viewer(2)));
        $this->assertFalse($access->canSee($piece, Viewer::nobody()), 'Nobody signed in sees nothing.');
    }

    #[DataProvider('presets')]
    public function test_shared_a_piece_is_deleted_by_its_starter_or_a_manager_only(DomainOptions $options): void
    {
        $access = new SessionAccess($options);
        $piece = self::piece($options);

        $this->assertTrue($access->canDelete($piece, new Viewer(1)));
        $this->assertTrue($access->canDelete($piece, new Viewer('1')), 'IDs compare as text.');
        $this->assertTrue($access->canDelete($piece, new Viewer(3, manager: true)));
        $this->assertFalse($access->canDelete($piece, new Viewer(2)));
        $this->assertFalse($access->canDelete($piece, Viewer::nobody()));
    }

    #[DataProvider('presets')]
    public function test_private_each_piece_is_its_starters_alone(DomainOptions $options): void
    {
        $access = new SessionAccess($options->with(shared: false));
        $piece = self::piece($options);

        $this->assertTrue($access->canSee($piece, new Viewer(1)));
        $this->assertTrue($access->canDelete($piece, new Viewer(1)));
        $this->assertFalse($access->canSee($piece, new Viewer(2)));
        $this->assertFalse($access->canSee($piece, new Viewer(2, manager: true)), 'A manager does not see private pieces.');
        $this->assertFalse($access->canDelete($piece, new Viewer(2, manager: true)));
        $this->assertSame([], $access->visible([$piece], new Viewer(2)));
        $this->assertSame([$piece], $access->visible([$piece], new Viewer(1)));
    }

    public function test_statamic_a_super_user_sees_and_deletes_private_pieces(): void
    {
        $access = new SessionAccess(DomainOptions::statamic(shared: false));
        $piece = self::piece(DomainOptions::statamic(), 'user-1');

        $this->assertTrue($access->canSee($piece, new Viewer('user-2', admin: true)));
        $this->assertTrue($access->canDelete($piece, new Viewer('user-2', admin: true)));
        $this->assertTrue((new SessionAccess(DomainOptions::statamic()))->canDelete($piece, new Viewer('user-2', admin: true)), 'Shared too.');
    }

    public function test_craft_and_filament_admins_are_not_owners(): void
    {
        foreach ([DomainOptions::craft(shared: false), DomainOptions::filament(shared: false)] as $options) {
            $this->assertFalse((new SessionAccess($options))->canSee(self::piece($options), new Viewer(2, admin: true)));
        }
    }

    public function test_statamic_a_piece_from_before_starters_were_kept_is_anyones(): void
    {
        $legacy = self::piece(DomainOptions::statamic(), null);

        $this->assertTrue((new SessionAccess(DomainOptions::statamic(shared: false)))->canSee($legacy, new Viewer('user-2')));
        $this->assertTrue((new SessionAccess(DomainOptions::statamic()))->canDelete($legacy, new Viewer('user-2')));
        $this->assertFalse((new SessionAccess(DomainOptions::craft(shared: false)))->canSee(self::piece(DomainOptions::craft(), null), new Viewer(2)));
        $this->assertFalse((new SessionAccess(DomainOptions::craft()))->canDelete(self::piece(DomainOptions::craft(), null), new Viewer(2)));
    }

    public function test_the_viewer_compares_ids_as_text(): void
    {
        $this->assertTrue((new Viewer(5))->is('5'));
        $this->assertFalse((new Viewer(5))->is(null));
        $this->assertFalse(Viewer::nobody()->is(null));
        $this->assertFalse((new Viewer(''))->isSomeone());
        $this->assertSame(Format::Craft, self::piece(DomainOptions::craft())->format);
    }
}
