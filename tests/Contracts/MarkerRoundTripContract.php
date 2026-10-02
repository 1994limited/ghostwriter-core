<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * What every addon's apply path must do with Ghostwriter's markers: a fact
 * to add (`[[ask: …]]`) and a link to choose (`#gw-link:`) put into a
 * field by the addon's **real** apply path (Statamic `MarkdownToBard` and
 * its entry writer, Craft's `Applier`, Filament's `FormState`), then read
 * back with the addon's own dialect, come back unchanged. The guide and
 * the publish guard find gaps by these markers, so one dropped on the way
 * in is a gap nobody is told about.
 *
 * The shapes are the field kinds a draft writes into:
 *
 * - `rich`: rich text (Bard, CKEditor, RichEditor), read back as markdown;
 * - `markdown`: a markdown field (Statamic `markdown`, Filament
 *   `MarkdownEditor`), read back as stored;
 * - `plain`: a text input or textarea. It can't hold a link mark, so the
 *   writer asks for the link instead (`[[ask: link to …]]`).
 *
 * An addon with no markdown field leaves `markdown` out of markerShapes().
 */
trait MarkerRoundTripContract
{
    /**
     * The markdown a draft has for a field of this shape, through the
     * addon's apply path into a saved (or form) value, and back as the
     * addon's dialect reads it.
     *
     * @param  'rich'|'markdown'|'plain'  $shape
     */
    abstract protected function roundTripMarkers(string $markdown, string $shape): string;

    /**
     * @return list<'rich'|'markdown'|'plain'>
     */
    protected function markerShapes(): array
    {
        return ['rich', 'markdown', 'plain'];
    }

    public function test_rich_text_keeps_a_fact_to_add_and_a_link_to_choose(): void
    {
        $this->assertBothMarkers($this->roundTripMarkers(self::markerText(), 'rich'), 'rich text');
    }

    public function test_rich_text_keeps_markers_in_headings_lists_and_emphasis(): void
    {
        $markdown = "## Open [[ask: opening days]]\n\n- Adults: [[ask: adult ticket price]]\n- **Children** go free. [Book a visit](#gw-link:booking-page)";
        $out = $this->roundTripMarkers($markdown, 'rich');

        $this->assertSame(['opening days', 'adult ticket price'], array_column(Markers::asks($out), 'hint'), "Rich text lost a marker:\n{$out}");
        $this->assertSame(['booking-page'], array_column(Markers::links($out), 'hint'), "Rich text lost a link to choose:\n{$out}");
        $this->assertStringContainsString('[[ask: adult ticket price]]', $out, 'The marker is written strictly, as it went in.');
    }

    public function test_a_markdown_field_keeps_both_markers(): void
    {
        if (! in_array('markdown', $this->markerShapes(), true)) {
            $this->markTestSkipped('This addon has no markdown field.');
        }

        $this->assertBothMarkers($this->roundTripMarkers(self::markerText(), 'markdown'), 'a markdown field');
    }

    public function test_plain_text_keeps_a_fact_to_add_and_a_link_asked_for(): void
    {
        $out = $this->roundTripMarkers('Tickets cost [[ask: adult ticket price]]. Ask us via [[ask: link to the contact page]].', 'plain');

        $this->assertSame(['adult ticket price', 'link to the contact page'], array_column(Markers::asks($out), 'hint'), "Plain text lost a marker:\n{$out}");
        $this->assertStringContainsString('[[ask: adult ticket price]]', $out);
    }

    public function test_text_without_markers_has_none_after(): void
    {
        foreach ($this->markerShapes() as $shape) {
            $out = $this->roundTripMarkers('Tickets cost £12 for adults. [Talk to us](/contact) today.', $shape);

            $this->assertFalse(Markers::has($out), "{$shape} grew a marker:\n{$out}");
        }
    }

    private static function markerText(): string
    {
        return 'Tickets cost [[ask: adult ticket price]] for adults. [Talk to us about Capacitor](#gw-link:contact-page) today.';
    }

    private function assertBothMarkers(string $out, string $where): void
    {
        $this->assertSame(['adult ticket price'], array_column(Markers::asks($out), 'hint'), "{$where} lost the fact to add:\n{$out}");
        $this->assertStringContainsString('[[ask: adult ticket price]]', $out, "{$where} changed how the fact to add is written.");

        $links = Markers::links($out);

        $this->assertSame(['contact-page'], array_column($links, 'hint'), "{$where} lost the link to choose:\n{$out}");
        $this->assertSame(['Talk to us about Capacitor'], array_column($links, 'words'), "{$where} changed the link's words.");
    }
}
