<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;

/**
 * What every addon's publish guard must do, through its real save hooks
 * (Gaps\PublishReadiness behind them):
 *
 * - publishing with a fact to add, or a link to choose, is refused, with
 *   the message on the field;
 * - saving a draft (or working copy, or provisional draft) with them
 *   passes;
 * - finished content publishes;
 * - warn mode publishes, with one warning;
 * - an unlicensed stock preview and a marker give one refusal naming both,
 *   and in warn mode one warning naming both;
 * - every other way the addon has of going live (Statamic's scheduled
 *   entry and working copy, Craft's applied draft) is guarded too.
 */
trait PublishGuardContract
{
    /** Set `publish.on_unfinished` (Craft: `onUnfinishedPublish`). */
    abstract protected function guardMode(OnPublish $mode): void;

    /**
     * A new entry, not yet saved, whose text field (guardTextField())
     * holds this markdown, and, with `$stockPreview`, whose image field
     * (guardImageField()) holds a stock photo previewed but not licensed.
     */
    abstract protected function guardEntry(string $text, bool $stockPreview = false): mixed;

    /** Save the entry as published (live, enabled), through the CMS. */
    abstract protected function guardPublish(mixed $entry): GuardOutcome;

    /** Save the entry as a draft (unpublished, a working copy, a provisional draft). */
    abstract protected function guardSaveDraft(mixed $entry): GuardOutcome;

    /**
     * Other ways the entry can go live, by name (a scheduled entry,
     * publishing a working copy, applying a draft): each must be refused
     * like guardPublish().
     *
     * @return array<string, GuardOutcome>
     */
    protected function guardOtherWaysLive(mixed $entry): array
    {
        return [];
    }

    /** The key the text field's errors are under. */
    protected function guardTextField(): string
    {
        return 'body';
    }

    /** The key the image field's errors are under. */
    protected function guardImageField(): string
    {
        return 'image';
    }

    public function test_publishing_with_a_fact_to_add_is_refused_with_the_message_on_the_field(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardPublish($this->guardEntry('Tickets cost [[ask: adult ticket price]] for adults.'));

        $this->assertFalse($outcome->saved, 'A page with a fact still to add must not go live.');
        $this->assertTrue($outcome->hasErrorOn($this->guardTextField()), 'The message belongs on the field: '.json_encode($outcome->errors));
        $this->assertStringContainsString('adult ticket price', $outcome->errorText());
    }

    public function test_publishing_with_a_link_to_choose_is_refused(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardPublish($this->guardEntry('[Talk to us](#gw-link:contact-page) today.'));

        $this->assertFalse($outcome->saved);
        $this->assertTrue($outcome->hasErrorOn($this->guardTextField()));
    }

    public function test_a_draft_with_markers_saves(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardSaveDraft($this->guardEntry('Tickets cost [[ask: adult ticket price]]. [Talk to us](#gw-link:contact-page).', stockPreview: true));

        $this->assertTrue($outcome->saved, 'Drafts always save: '.json_encode($outcome->errors));
        $this->assertSame([], $outcome->errors);
    }

    public function test_finished_content_publishes(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardPublish($this->guardEntry('Tickets cost £12 for adults. [Talk to us](/contact).'));

        $this->assertTrue($outcome->saved, json_encode($outcome->errors) ?: '');
        $this->assertSame([], $outcome->warnings);
    }

    public function test_warn_mode_publishes_and_warns_once(): void
    {
        $this->guardMode(OnPublish::Warn);
        $outcome = $this->guardPublish($this->guardEntry('Tickets cost [[ask: adult ticket price]] for adults.'));

        $this->assertTrue($outcome->saved);
        $this->assertCount(1, $outcome->warnings);
        $this->assertStringContainsString('adult ticket price', $outcome->warnings[0]);
    }

    public function test_a_stock_preview_and_a_marker_give_one_message_with_both(): void
    {
        $this->guardMode(OnPublish::Block);
        $blocked = $this->guardPublish($this->guardEntry('Tickets cost [[ask: adult ticket price]].', stockPreview: true));

        $this->assertFalse($blocked->saved);
        $this->assertTrue($blocked->hasErrorOn($this->guardTextField()));
        $this->assertTrue($blocked->hasErrorOn($this->guardImageField()), 'The preview is named on the image field: '.json_encode($blocked->errors));

        $this->guardMode(OnPublish::Warn);
        $warned = $this->guardPublish($this->guardEntry('Tickets cost [[ask: adult ticket price]].', stockPreview: true));

        $this->assertTrue($warned->saved);
        $this->assertCount(1, $warned->warnings, 'One guard, one message: '.json_encode($warned->warnings));
        $this->assertStringContainsString('adult ticket price', $warned->warnings[0]);
        $this->assertStringContainsString('preview', $warned->warnings[0]);
    }

    public function test_every_other_way_of_going_live_is_guarded(): void
    {
        $this->guardMode(OnPublish::Block);

        foreach ($this->guardOtherWaysLive($this->guardEntry('Tickets cost [[ask: adult ticket price]].')) as $way => $outcome) {
            $this->assertFalse($outcome->saved, "{$way} let a page with a fact to add go live.");
        }

        $this->addToAssertionCount(1);
    }
}
