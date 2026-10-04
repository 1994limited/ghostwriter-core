<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * What every addon's apply path must do with a link the SEO pass inserted
 * (SEO layer §7.4, §20): the href inlineHref() gives for a real page of
 * the site goes through the addon's real path into a rich-text field (Bard,
 * CKEditor, Filament's RichEditor), reads back through the dialect as a
 * link to the same page with the same words, next to Ghostwriter's own
 * markers, and the CMS renders it as that page's address.
 */
trait LinkInsertContract
{
    /** The addon's link dialect. */
    abstract protected function inlineLinks(): InlineLinks;

    /** A real, published page of the site, as the addon's link index gives it. */
    abstract protected function linkTarget(): DigestEntry;

    /** A rich-text field of the addon's, as its reader reads it, whose editor has a link button. */
    abstract protected function linkField(): Field;

    /**
     * Markdown through the addon's real apply path into the field, read
     * back as markdown with the addon's dialect.
     */
    abstract protected function storedMarkdown(string $markdown, Field $field): string;

    /** The address the CMS renders a stored link with this href as, on the page. */
    abstract protected function renderedHref(string $href): ?string;

    /** The target page's own address, as the CMS renders it. */
    abstract protected function targetUrl(): string;

    public function test_an_inserted_link_survives_the_apply_path_and_renders_as_its_page(): void
    {
        $href = $this->inlineLinks()->inlineHref($this->linkTarget());
        $this->assertNotNull($href, 'The dialect can link to a page of the site.');

        $markdown = "Winter is when a garden is set up for the year.\n\nIf you would like a visit, [tell us about your garden]({$href}) and we will arrange a walk round.\n\nA visit costs [[ask: the price of a visit]]; [book one](#gw-link:booking-page) when you are ready.";
        $stored = $this->storedMarkdown($markdown, $this->linkField());

        $this->assertMatchesRegularExpression('/\[tell us about your garden\]\(<?([^()\s>]+)>?\)/', $stored, 'The words are still a link.');
        preg_match('/\[tell us about your garden\]\(<?([^()\s>]+)>?\)/', $stored, $match);
        $this->assertSame(LinkCandidates::linkKey($href), LinkCandidates::linkKey($match[1]), 'It still goes to the same page.');
        $this->assertStringContainsString('If you would like a visit,', $stored);
        $this->assertStringContainsString('[[ask: the price of a visit]]', $stored, 'A fact to add survives beside it.');
        $this->assertStringContainsString('#gw-link:booking-page', $stored, 'A link to choose survives beside it.');

        $this->assertSame(rtrim($this->targetUrl(), '/'), rtrim((string) $this->renderedHref($match[1]), '/'), 'The page renders it as the target\'s address.');
    }

    public function test_a_page_the_dialect_cant_link_to_inline_gives_no_href(): void
    {
        $this->assertNull($this->inlineLinks()->inlineHref(new DigestEntry(null, 'Nowhere', null, '', null)));
    }
}
