<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposal;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Studio\StudioTestCase;

/**
 * Finish's **Suggest links** on an existing page with no link to the site
 * (SEO layer §12, `few-links`): SeoPass::suggestLinksFor() runs the first
 * draft's `seo-editor` (links only) and `seo-verifier` on the page's
 * current text, and proposes what passes, by field and words, for the
 * editor to link one by one. Nothing is written.
 */
final class PageLinksTest extends StudioTestCase
{
    /** About 320 words, as the editor's Bard stores it: two links wanted. */
    private const BODY = '<p>Winter is when a garden is set up for the year ahead, and a little care now saves a lot of work in spring. Most borders need less than people think, and the jobs that matter are few and simple.</p>'
        .'<h2>Cutting back</h2><p>We cut back only what has finished and would rot or smother the plants beneath it. Seed heads of sedum, teasel and grasses stay standing, as they feed the birds and look good on a frosty morning. If you have a planting plan we drew for you, we follow it, so the shape of the garden holds through the cold months and the borders come back as they were meant to. Old stems of perennials are cut to a hand\'s height, and anything diseased goes off site rather than onto the compost heap.</p>'
        .'<h2>Mulching and feeding</h2><p>A thick layer of our own compost goes on every bed once the ground is wet and before it freezes. It keeps the roots warm, holds the moisture in and feeds the soil slowly through the winter. Roses get a handful of feed in late February, and young hedges a little more, so they start the spring strong. We never mulch over crowns that would sit wet, such as delphiniums, and we leave a ring of bare soil around the stems of shrubs.</p>'
        .'<h2>Protecting tender plants</h2><p>Tender plants in pots move against a south wall or into a cold greenhouse, wrapped in fleece on the coldest nights. Tree ferns get their crowns stuffed with straw, and banana stems are wrapped in hessian. Nothing is wrapped in plastic, which traps damp and does more harm than the frost would.</p>'
        .'<h2>Booking a visit</h2><p>We look after gardens across Northumberland, Durham and the Tyne Valley from November to February. If you would like a winter visit, tell us about your garden and we will arrange a first walk round before the cold sets in.</p>';

    private static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', type: 'bard', meta: [HeadingLevels::META => [2, 3]]),
        ]);
    }

    private static function page(string $body = self::BODY, ?LinkProposals $proposals = null): GapContext
    {
        return new GapContext(
            schema: self::schema(),
            entry: new EntryData(['title' => 'Winter garden care', 'body' => $body], id: 'winter', group: 'journal', site: 'default'),
            richText: new HtmlDialect,
            proposals: $proposals,
        );
    }

    private static function index(): MemoryEntryIndex
    {
        $index = new MemoryEntryIndex('en');
        $index->put(IndexRow::make(new EntryRef('services', 'plans', 'default'), IndexScope::Link, 'Planting plans', '/garden-services/planting-plans', 'A planting plan for every border: what to grow, where, and how to keep it looking right through the year.', 'Garden services', link: 'entry::plans', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('pages', 'contact', 'default'), IndexScope::Link, 'Contact us', '/contact', 'Tell us about your garden and book a first visit from our team in Northumberland.', 'Pages', key: true, link: 'entry::contact', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('journal', 'october', 'default'), IndexScope::Full, 'What to do in the garden in October', '/journal/october', 'Leave seed heads standing, plant bulbs and mulch the borders before winter.', 'Journal', link: 'entry::october', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('journal', 'winter', 'default'), IndexScope::Full, 'Winter garden care', '/journal/winter-garden-care', 'Winter care for established gardens: cutting back, mulching and protecting tender plants.', 'Journal', link: 'entry::winter', locale: 'en'));

        return $index;
    }

    private static function links(?MemoryEntryIndex $index = null): LinkContext
    {
        return new LinkContext($index ?? self::index(), new StatamicLinks, 'journal', 'default', new EntryRef('journal', 'winter', 'default'), new ContentKind('journal', 'Journal'), 'Plain and warm.', 'en_GB');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $input = 100, int $output = 50): TextResponse
    {
        return self::reply((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $input, $output);
    }

    private function script(): void
    {
        // Units, as SeoRequest numbers them: u1 the title, u2 the lead, u3 "Cutting back"… u6 "Booking a visit".
        $this->fake->respond('seo-editor', self::json(['notes' => 'Winter care; plans and contact fit.', 'links' => [
            ['unit' => 'u3', 'exact' => 'planting plan we drew for you', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'The sentence is about following a plan.'],
            ['unit' => 'u6', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e3', 'hint' => '', 'why' => 'An invitation to get in touch.'],
            ['unit' => 'u4', 'exact' => 'click here', 'prefix' => '', 'target' => 'e2', 'hint' => '', 'why' => 'Vague.'],
        ], 'markers' => [], 'title' => '', 'description' => ''], 1200, 300));
        $this->fake->respond('seo-verifier', self::json(['verdicts' => [
            ['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.'],
            ['notes' => 'Right page.', 'id' => 'l2', 'verdict' => 'keep', 'reason' => 'Fits.'],
        ]], 600, 100));
    }

    private function pass(): SeoPass
    {
        return new SeoPass(logger: $this->logger(), studio: $this->studio());
    }

    public function test_an_existing_page_gets_links_proposed_by_field_and_words(): void
    {
        $this->script();
        $pass = $this->pass();
        $found = $pass->suggestLinksFor(self::page(), self::links());

        $this->assertSame(['seo-editor', 'seo-verifier'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()));
        $this->assertCount(2, $found->links);
        $this->assertSame('', $found->none);
        [$plans, $contact] = $found->links;

        $this->assertSame('body', $plans->path->toString());
        $this->assertSame('Body', $plans->label);
        $this->assertSame('planting plan we drew for you', $plans->words);
        $this->assertSame('planting plan we drew for you', $plans->quote->exact);
        $this->assertStringEndsWith('If you have a ', $plans->quote->prefix);
        $this->assertStringStartsWith(', we follow it', $plans->quote->suffix);
        $this->assertSame('statamic://entry::plans', $plans->href, 'As Bard stores a link (inlineHref()).');
        $this->assertSame(['Planting plans', 'Garden services', '/garden-services/planting-plans', 'The sentence is about following a plan.'], [$plans->title, $plans->type, $plans->url, $plans->why]);
        $this->assertSame(['tell us about your garden', 'statamic://entry::contact', 'Contact us'], [$contact->words, $contact->href, $contact->title]);
        $this->assertSame(['l1', 'l2'], [$plans->id, $contact->id]);
        $this->assertSame([1200 + 600, 300 + 100], [$pass->spent()->input, $pass->spent()->output], 'Both calls are counted.');
    }

    public function test_the_editor_is_asked_for_links_only_by_the_first_drafts_rules(): void
    {
        $this->script();
        $this->pass()->suggestLinksFor(self::page(), self::links());

        $request = $this->fake->prompted('seo-editor')[0];
        $this->assertStringContainsString('The page: "Winter garden care" (Journal)', $request->prompt);
        $this->assertStringContainsString('Links it has already: 0. Add at most 2, and fewer, or none, when nothing fits.', $request->prompt, 'About one per 250 words, 2 to 5.');
        $this->assertStringContainsString('[u3] (section, links allowed)', $request->prompt);
        $this->assertStringContainsString('e3. Contact us (Pages) · /contact', $request->prompt, 'Key pages are candidates.');
        $this->assertStringNotContainsString('/journal/winter-garden-care', $request->prompt, 'Never the page itself.');
        $this->assertStringNotContainsString('search title', $request->prompt, 'Links only: no title or description.');
        $this->assertSame(2, $request->schema?->schema['properties']['links']['maxItems']);
        $this->assertSame([6000, 'high'], [RequestLog::records([$request])[0]['maxTokens'], RequestLog::records([$request])[0]['effort']], 'Top tier, high effort, as a draft\'s.');
        $this->assertTrue(Agents::cachesInstructions('seo-editor') && Agents::cachesInstructions('seo-verifier'));
        $this->assertSame(Agents::WRITING, Agents::tier('seo-editor'));
        $this->assertStringContainsString('Plain and warm.', $request->instructions, 'The cached instructions, as for a draft.');
    }

    public function test_a_link_the_verifier_drops_is_not_proposed(): void
    {
        $this->script();
        $this->fake->reset('seo-verifier');
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [
            ['notes' => '…', 'id' => 'l1', 'verdict' => 'drop', 'reason' => 'Not about plans.'],
            ['notes' => '…', 'id' => 'l2', 'verdict' => 'keep', 'reason' => 'Fits.'],
        ]]);

        $found = $this->pass()->suggestLinksFor(self::page(), self::links());

        $this->assertSame(['statamic://entry::contact'], array_map(fn (LinkProposal $link) => $link->href, $found->links));
    }

    public function test_a_failed_verifier_proposes_what_passed_the_checks_and_a_failed_editor_throws(): void
    {
        $this->script();
        $this->fake->reset('seo-verifier');
        $this->fake->respond('seo-verifier', fn () => throw new ProviderException('The provider is busy.', 'fake'));
        $this->assertCount(2, $this->pass()->suggestLinksFor(self::page(), self::links())->links);

        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', fn () => throw new ProviderException('The provider is busy.', 'fake'));
        $this->expectException(ProviderException::class);
        $this->pass()->suggestLinksFor(self::page(), self::links());
    }

    public function test_no_page_to_link_to_makes_no_call(): void
    {
        // The page itself and a page search engines are told to skip: nothing the list can offer.
        $index = new MemoryEntryIndex('en');
        $index->put(IndexRow::make(new EntryRef('journal', 'winter', 'default'), IndexScope::Full, 'Winter garden care', '/journal/winter-garden-care', 'Winter care for established gardens.', 'Journal', link: 'entry::winter', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('journal', 'summer', 'default'), IndexScope::Full, 'Summer watering', '/journal/summer-watering', 'How often to water pots in a heatwave.', 'Journal', noindex: true, link: 'entry::summer', locale: 'en'));

        $found = $this->pass()->suggestLinksFor(self::page(), self::links($index));

        $this->assertSame([], $this->fake->requests());
        $this->assertSame(LinkProposals::NO_CANDIDATES, $found->none);
        $this->assertTrue($found->isEmpty());
    }

    public function test_a_page_with_links_enough_or_too_few_words_makes_no_call(): void
    {
        $linked = str_replace('a planting plan we drew', '<a href="statamic://entry::plans">a planting plan</a> we drew', self::BODY);
        $linked = str_replace('tell us about your garden', '<a href="/contact">tell us about your garden</a>', $linked);

        $this->assertSame(LinkProposals::NO_ROOM, $this->pass()->suggestLinksFor(self::page($linked), self::links())->none, 'Two links to the site on a page of 320 words.');
        $this->assertSame(LinkProposals::NO_ROOM, $this->pass()->suggestLinksFor(self::page('<p>Mulch the borders before the frost.</p>'), self::links())->none);
        $this->assertSame([], $this->fake->requests());
    }

    public function test_words_with_formatting_in_them_are_left_out(): void
    {
        $this->script();
        $body = str_replace('planting plan we drew for you', 'planting plan we <em>drew</em> for you', self::BODY);
        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', self::json(['notes' => '…', 'links' => [
            ['unit' => 'u3', 'exact' => 'planting plan we *drew* for you', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'A plan.'],
            ['unit' => 'u6', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e3', 'hint' => '', 'why' => 'Contact.'],
        ], 'markers' => [], 'title' => '', 'description' => '']));

        $found = $this->pass()->suggestLinksFor(self::page($body), self::links());

        $this->assertSame(['tell us about your garden'], array_map(fn (LinkProposal $link) => $link->words, $found->links));
    }

    public function test_each_proposal_still_to_make_is_a_step_and_few_links_steps_aside(): void
    {
        $this->script();
        $found = $this->pass()->suggestLinksFor(self::page(), self::links());
        $report = GapFinder::standard()->find(self::page(proposals: $found));
        $steps = $report->ofKind(GapKind::LinkProposed);

        $this->assertSame([], $report->ofKind(GapKind::FewLinks), 'The links found are the steps.');
        $this->assertCount(2, $steps);
        $this->assertSame(['planting plan we drew for you', 'tell us about your garden'], array_map(fn ($gap) => $gap->hint, $steps));
        $this->assertSame(['link', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $steps[0]->fixes));
        $this->assertSame(['gaps.fix.link-it', 'gaps.fix.skip'], array_map(fn ($fix) => $fix->label->key, $steps[0]->fixes));
        $this->assertSame('statamic://entry::plans', $steps[0]->fixes[0]->value);
        $this->assertTrue($steps[0]->fixes[0]->primary);
        $this->assertTrue($steps[0]->meta['inline']);
        $this->assertSame('Link “planting plan we drew for you” to Planting plans? The sentence is about following a plan.', $steps[0]->message()->english());
        $this->assertSame('If you have a planting plan we drew for you, we follow it, so the shape of the garden holds through the cold months and the borders come back as they were meant to.', $steps[0]->excerpt);
        $this->assertSame(0, $report->count(), 'Suggestions: never counted.');

        // Linked in the form: that step goes, and the page links to the site now.
        $linked = str_replace('planting plan we drew for you', '<a href="statamic://entry::plans">planting plan we drew for you</a>', self::BODY);
        $report = GapFinder::standard()->find(self::page($linked, $found));
        $this->assertSame(['tell us about your garden'], array_map(fn ($gap) => $gap->hint, $report->ofKind(GapKind::LinkProposed)));
        $this->assertSame([], $report->ofKind(GapKind::FewLinks));

        // The words edited away: nothing to link, and with none left, few-links is back.
        $edited = str_replace(['planting plan we drew for you', 'tell us about your garden'], ['plan', 'write to us'], self::BODY);
        $report = GapFinder::standard()->find(self::page($edited, $found));
        $this->assertSame([], $report->ofKind(GapKind::LinkProposed));
        $this->assertSame(['suggest-links', 'focus', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $report->ofKind(GapKind::FewLinks)[0]->fixes));
    }

    public function test_nothing_found_says_so_and_offers_a_link_by_hand(): void
    {
        $report = GapFinder::standard()->find(self::page(proposals: LinkProposals::none(LinkProposals::NO_CANDIDATES)));
        $gap = $report->ofKind(GapKind::FewLinks)[0];

        $this->assertSame('No pages close enough to link to. Add a link by hand where one fits, or skip this.', $gap->message()->english());
        $this->assertSame(['focus', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $gap->fixes));
        $this->assertTrue($gap->fixes[0]->primary);
    }

    public function test_a_repeat_of_the_words_is_counted_as_the_editor_finds_it(): void
    {
        $body = '<p>Tell us about your garden, or <a href="https://example.org">tell us about your garden</a> elsewhere.</p>'.self::BODY;
        $proposal = new LinkProposal('l1', FieldPath::of('body'), 'Body', new TextQuote('tell us about your garden', 'If you would like a winter visit, ', ' and we will arrange'), 'tell us about your garden', 'statamic://entry::contact', 'Contact us');
        $steps = GapFinder::standard()->find(self::page($body.'<p>'.str_repeat('mulch ', 10).'</p>', new LinkProposals([$proposal])))->ofKind(GapKind::LinkProposed);

        $this->assertCount(1, $steps);
        $this->assertSame(0, $steps[0]->occurrence, 'The capitalised one differs, and the linked one isn\'t counted.');

        $body = '<p>We say: tell us about your garden. </p>'.self::BODY;
        $steps = GapFinder::standard()->find(self::page($body, new LinkProposals([$proposal])))->ofKind(GapKind::LinkProposed);
        $this->assertSame(1, $steps[0]->occurrence, 'The second unlinked repeat in the field.');
    }

    public function test_a_page_that_links_to_the_target_already_has_no_step_for_it(): void
    {
        $proposal = new LinkProposal('l1', FieldPath::of('body'), 'Body', new TextQuote('tell us about your garden'), 'tell us about your garden', 'statamic://entry::contact', 'Contact us');
        $body = str_replace('Most borders', '<a href="statamic://entry::contact">Ask us</a>. Most borders', self::BODY);

        $this->assertSame([], GapFinder::standard()->find(self::page($body, new LinkProposals([$proposal])))->ofKind(GapKind::LinkProposed));
    }

    public function test_proposals_round_trip_for_the_addons_cache(): void
    {
        $this->script();
        $found = $this->pass()->suggestLinksFor(self::page(), self::links());
        $again = LinkProposals::fromArray(json_decode((string) json_encode($found->toArray()), true));

        $this->assertEquals($found->toArray(), $again->toArray());
        $this->assertSame(LinkProposals::DROPPED, LinkProposals::fromArray(LinkProposals::none(LinkProposals::DROPPED)->toArray())->none);
        $this->assertSame([], LinkProposals::fromArray(['links' => [['path' => 'body']]])->links, 'Anything that isn\'t a proposal is left out.');
    }
}
