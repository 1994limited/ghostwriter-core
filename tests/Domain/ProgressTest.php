<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Progress;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Record;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use PHPUnit\Framework\TestCase;

/**
 * E6: a piece counts as finished only once its record is saved.
 */
final class ProgressTest extends TestCase
{
    private static function piece(DomainOptions $options): Session
    {
        return Session::start($options->format, 'guide', ['subject' => 'Bulbs'], 1);
    }

    public function test_a_new_piece_goes_from_interview_to_draft_to_the_form_to_saved(): void
    {
        foreach ([DomainOptions::statamic(), DomainOptions::craft()] as $options) {
            $session = self::piece($options);
            $this->assertEquals(new Progress('interview', false), Progress::of($session, Record::none(), $options));

            $session->draft = "title: Bulbs\n";
            $this->assertEquals(new Progress('draft', false), Progress::of($session, Record::none(), $options));

            $session->markApplied(1);
            $this->assertEquals(new Progress('in_form', false), Progress::of($session, Record::none(), $options), 'Put into the form, not saved: not finished.');

            $this->assertEquals(new Progress('saved', true), Progress::of($session, Record::saved(), $options));
            $this->assertEquals(new Progress('published', true), Progress::of($session, Record::saved(true), $options));
        }
    }

    public function test_a_craft_unpublished_draft_is_not_saved(): void
    {
        $options = DomainOptions::craft();
        $session = self::piece($options);
        $session->draft = "title: Bulbs\n";
        $session->markApplied(1);

        $this->assertEquals(new Progress('in_form', false), Progress::of($session, Record::unsaved(), $options));
    }

    public function test_working_and_failed_come_first_and_are_never_finished(): void
    {
        foreach ([DomainOptions::statamic(), DomainOptions::craft()] as $options) {
            $session = self::piece($options);
            $session->claim(1, $options);
            $this->assertEquals(new Progress('working', false), Progress::of($session, Record::saved(), $options));

            $session->fail('x');
            $this->assertEquals(new Progress('failed', true), Progress::of($session, Record::saved(), $options), 'Failed after it was saved: still finished.');
        }
    }

    public function test_statamic_an_edit_is_finished_once_the_record_is_saved_after_the_changes(): void
    {
        $options = DomainOptions::statamic();
        $session = self::piece($options);
        $session->source = 'entry-1';
        $session->editing = true;

        $this->assertEquals(new Progress('editing', false), Progress::of($session, Record::saved(false, new DateTimeImmutable('2026-10-02T09:00:00+00:00')), $options));

        $session->markApplied(1, new DateTimeImmutable('2026-10-02T10:00:00+00:00'));
        $this->assertEquals(new Progress('changed', false), Progress::of($session, Record::saved(false, new DateTimeImmutable('2026-10-02T09:00:00+00:00')), $options));
        $this->assertEquals(new Progress('published', true), Progress::of($session, Record::saved(true, new DateTimeImmutable('2026-10-02T10:00:05+00:00')), $options));
    }

    public function test_craft_and_filament_an_edit_is_finished_once_its_changes_are_put_in(): void
    {
        foreach ([DomainOptions::craft(), DomainOptions::filament()] as $options) {
            $session = self::piece($options);
            $session->source = 10;
            $session->editing = true;

            $this->assertFalse(Progress::of($session, Record::saved(), $options)->finished);

            $session->markApplied(1);
            $progress = Progress::of($session, Record::saved(), $options);
            $this->assertTrue($progress->finished);
            $this->assertSame('changed', $progress->stage);
        }
    }

    public function test_filament_names_its_stages_and_counts_a_saved_record_first(): void
    {
        $options = DomainOptions::filament();
        $session = self::piece($options);

        $this->assertEquals(new Progress('asking', false), Progress::of($session, Record::none(), $options));

        $session->answer('Here.', "title: Bulbs\n");
        $this->assertEquals(new Progress('ready', false), Progress::of($session, Record::none(), $options));

        $session->answer('Who is it for?', null);
        $this->assertEquals(new Progress('asking', false), Progress::of($session, Record::none(), $options), 'Asking a question, even with a draft.');

        $session->claim(1, $options);
        $this->assertEquals(new Progress('writing', false), Progress::of($session, Record::none(), $options));
        $this->assertEquals(new Progress('saved', true), Progress::of($session, Record::saved(), $options), 'Its record is saved: finished, even while working again.');
    }

    public function test_the_stages_are_the_documented_ones(): void
    {
        $this->assertContains('in_form', Progress::STAGES);
        $this->assertSame(Format::Filament, DomainOptions::filament()->format);
    }
}
