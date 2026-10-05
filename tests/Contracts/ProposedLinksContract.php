<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposal;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;

/**
 * What every addon's Finish this page must do with Suggest links' proposals
 * (SEO layer §12, `few-links`): an entry whose rich-text field holds a long
 * page with no link to the site, stored through the addon's real path and
 * read by its real Finish context, gets one "Link it" step per proposal
 * (`link-proposed`, the href as the field stores links) and no "Link to
 * your other pages" step beside them. Once the words are linked (stored
 * the same way), the step goes and the page counts as linked. With nothing
 * found, "Link to your other pages" says so.
 */
trait ProposedLinksContract
{
    /**
     * The addon's Finish context for an entry whose rich-text field
     * (proposalPath()) holds $markdown, stored through the addon's real
     * path (Bard, CKEditor, the RichEditor's HTML), with $proposals as the
     * addon hands them to the gap finder.
     */
    abstract protected function finishContext(string $markdown, ?LinkProposals $proposals = null): GapContext;

    /** Where that field is, as the gap finder walks the entry. */
    abstract protected function proposalPath(): FieldPath;

    /** A link to a real page of the site, as the field's rich text stores it (InlineLinks::inlineHref()). */
    abstract protected function proposalHref(): string;

    private static function longPage(string $last = 'If you would like a winter visit, tell us about your garden and we will arrange a first walk round.'): string
    {
        $paragraph = 'We cut back the grasses that have stood all winter, lift and divide the perennials that have grown too big, and mulch the borders while the soil is still damp. Roses are pruned to an outward bud, and climbers are tied in along the wires.';

        return "## Winter work\n\n".implode("\n\n", array_fill(0, 8, $paragraph))."\n\n## Booking a visit\n\n{$last}";
    }

    /** A proposal for “tell us about your garden”, quoted from the field as the addon reads it. */
    private function proposal(): LinkProposal
    {
        $context = $this->finishContext(self::longPage());
        $text = null;

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($visit->path->equals($this->proposalPath())) {
                $text = Walk::text($visit, $context->richText);
            }
        }

        $this->assertNotNull($text, 'The field is read.');
        $found = (new QuoteFinder)->find(new TextQuote('tell us about your garden'), (string) $text);
        $this->assertNotNull($found, 'The words read back as they were written.');

        return new LinkProposal('l1', $this->proposalPath(), 'Body', TextQuote::around((string) $text, $found->offset, $found->length), 'tell us about your garden', $this->proposalHref(), 'Contact us', 'Pages', '/contact', 'An invitation to get in touch.');
    }

    public function test_a_proposal_is_a_link_it_step_in_place_of_link_to_your_other_pages(): void
    {
        $report = GapFinder::standard()->find($this->finishContext(self::longPage(), new LinkProposals([$this->proposal()])));
        $steps = $report->ofKind(GapKind::LinkProposed);

        $this->assertCount(1, $steps);
        $this->assertTrue($steps[0]->path->equals($this->proposalPath()));
        $this->assertSame('tell us about your garden', $steps[0]->hint);
        $this->assertSame(['link', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $steps[0]->fixes));
        $this->assertSame($this->proposalHref(), $steps[0]->fixes[0]->value);
        $this->assertSame([], $report->ofKind(GapKind::FewLinks));
    }

    public function test_once_the_words_are_linked_the_step_goes(): void
    {
        $proposal = $this->proposal();
        $report = GapFinder::standard()->find($this->finishContext(self::longPage("If you would like a winter visit, [tell us about your garden]({$this->proposalHref()}) and we will arrange a first walk round."), new LinkProposals([$proposal])));

        $this->assertSame([], $report->ofKind(GapKind::LinkProposed));
        $this->assertSame([], $report->ofKind(GapKind::FewLinks), 'The page links to the site now.');
    }

    public function test_without_suggest_links_the_page_offers_it_and_with_nothing_found_says_so(): void
    {
        $before = GapFinder::standard()->find($this->finishContext(self::longPage()))->ofKind(GapKind::FewLinks);
        $this->assertCount(1, $before);
        $this->assertSame('suggest-links', $before[0]->fixes[0]->action->value);

        $none = GapFinder::standard()->find($this->finishContext(self::longPage(), LinkProposals::none(LinkProposals::NO_CANDIDATES)))->ofKind(GapKind::FewLinks);
        $this->assertCount(1, $none);
        $this->assertSame('gaps.few-links.none', $none[0]->message()->key);
        $this->assertSame(['focus', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $none[0]->fixes));
    }
}
