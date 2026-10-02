<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use PHPUnit\Framework\TestCase;

final class GuidesTest extends TestCase
{
    public function test_the_image_guide_is_read_by_group(): void
    {
        $guide = new Guide(Guide::IMAGERY, "Intro.\n\n## Journal\n\nSoft daylight.\n\n## Pages\n\nWide views.\n");

        $this->assertSame('Soft daylight.', $guide->section('Journal'));
        $this->assertSame('Wide views.', $guide->section('pages'));
        $this->assertSame('', $guide->section('Shop'), 'Divided by group, and nothing for this one.');
        $this->assertSame('All of it.', (new Guide(Guide::IMAGERY, "All of it.\n"))->section('Shop'));
    }

    public function test_a_guide_is_saved_with_one_trailing_newline(): void
    {
        $this->assertSame("Voice\n", Guide::normalise("Voice  \n\n"));
        $this->assertFalse((new Guide(Guide::VOICE, " \n"))->exists());
    }

    public function test_one_scan_or_refinement_at_a_time_and_a_failure_shown_once(): void
    {
        $state = GuideState::empty(Format::Statamic);
        $state->begin('scan');

        try {
            $state->begin('refine');
            $this->fail('Conflict');
        } catch (Conflict $conflict) {
            $this->assertSame('Ghostwriter is still working on the last request.', $conflict->getMessage());
        }

        $state->fail('Overloaded.');
        $this->assertNull($state->task);
        $this->assertTrue($state->forgetFailure());
        $this->assertFalse($state->forgetFailure());
        $this->assertSame('idle', $state->status);
    }

    public function test_work_that_stopped_is_shown_failed(): void
    {
        $options = DomainOptions::craft();
        $state = GuideState::empty(Format::Craft);
        $state->begin('scan');
        $state->changedAt = '2026-10-02 12:00:00';

        $this->assertFalse($state->recoverIfStale($options, new DateTimeImmutable('2026-10-02T12:10:00+00:00')));
        $this->assertTrue($state->recoverIfStale($options, new DateTimeImmutable('2026-10-02T12:15:00+00:00')));
        $this->assertSame(DomainOptions::STOPPED, $state->error);

        $analysis = new Analysis('working');
        $this->assertFalse($analysis->recoverIfStale($options), 'Not without knowing when it changed.');
    }

    public function test_the_refine_conversation_is_kept(): void
    {
        $state = GuideState::empty(Format::Filament);
        $state->addMessage('user', 'Shorter.');

        $this->assertSame(['status' => 'idle', 'error' => null, 'task' => null, 'messages' => [['role' => 'user', 'content' => 'Shorter.']], 'scanned' => []], $state->toArray());
        $this->assertArrayHasKey('pending', GuideState::empty(Format::Craft)->toArray());
    }
}
