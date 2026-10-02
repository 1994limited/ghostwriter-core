<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Ulid;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function test_ids_are_made_as_each_addon_makes_them(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', Format::Statamic->newId());
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', Format::Filament->newId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{26}$/', Format::Craft->newId());
        $this->assertNotSame(Format::Craft->newId(), Format::Craft->newId());
    }

    public function test_a_ulid_starts_with_its_time_and_sorts_by_it(): void
    {
        $this->assertSame('01M3WWM3KK', substr(Ulid::generate(1790897163891), 0, 10));
        $this->assertLessThan(0, strcmp(Ulid::generate(1000), Ulid::generate(2000)));
    }

    public function test_session_ids_are_checked_before_any_lookup(): void
    {
        $this->assertTrue(Format::Statamic->isSessionId('01M3WWM3KKW1FV098209K5HHYE'));
        $this->assertFalse(Format::Statamic->isSessionId('../01M3WWM3KKW1FV098209K5HH'));
        $this->assertTrue(Format::Craft->isSessionId('4a34cea91b36e0ccaabef915f1'));
        $this->assertFalse(Format::Craft->isSessionId('01M3WWM3KKW1FV098209K5HHYE'));
        $this->assertTrue(Format::Filament->isSessionId('01m3ycrdns656yxamjqnhy8h9e'));
    }

    public function test_moments_are_written_and_read_in_each_format(): void
    {
        $at = new DateTimeImmutable('2026-10-02T14:00:00+01:00');

        $this->assertSame('2026-10-02T14:00:00+01:00', Format::Statamic->stamp($at));
        $this->assertSame('2026-10-02 13:00:00', Format::Filament->stamp($at));
        $this->assertSame($at->getTimestamp(), Format::parse('2026-10-02 13:00:00')?->getTimestamp());
        $this->assertSame(1790907895, Format::parse(1790907895)?->getTimestamp());
        $this->assertSame(1790907895, Format::parse('1790907895')?->getTimestamp());
        $this->assertNull(Format::parse('not a date'));
        $this->assertNull(Format::parse(''));
    }

    public function test_options_keep_each_addons_differences(): void
    {
        $this->assertTrue(DomainOptions::statamic()->adminSeesAll);
        $this->assertTrue(DomainOptions::craft()->editFinishedOnApply);
        $this->assertSame(['working' => 'writing', 'draft' => 'ready', 'interview' => 'asking'], DomainOptions::filament()->stageNames);
        $this->assertFalse(DomainOptions::craft(shared: false)->shared);
        $this->assertSame(1080, DomainOptions::statamic(jobTimeout: 420)->staleAfter());
        $this->assertSame(DomainOptions::filament()->stageNames, DomainOptions::filament()->with(shared: false)->stageNames);

        $this->expectException(InvalidArgumentException::class);
        new DomainOptions(Format::Craft, jobTimeout: 0);
    }
}
