<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
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
}
