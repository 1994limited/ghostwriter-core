<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Studio\StudioTestCase;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * Internal links in the writing pipeline (SEO layer §5.1, §7, row 4): on a
 * first draft, one `seo-editor` and one `seo-verifier` call between the
 * writer and the layout planner; the links checked in code and written into
 * the writer's text before units and layouts are made; none on later turns
 * or edits; LinkGuard on every writer turn.
 */
final class SeoLinksTest extends StudioTestCase
{
    /** About 330 words of prose: two links wanted. */
    private const WRITER = <<<'MD'
        <reply>Here is a first draft.</reply>
        <draft>
        title: Winter garden care
        body: |
          Winter is when a garden is set up for the year ahead, and a little care now saves a lot of work in spring. Most borders need less than people think, and the jobs that matter are few and simple.

          ## Cutting back

          We cut back only what has finished and would rot or smother the plants beneath it. Seed heads of sedum, teasel and grasses stay standing, as they feed the birds and look good on a frosty morning. If you have a planting plan we drew for you, we follow it, so the shape of the garden holds through the cold months and the borders come back as they were meant to. Old stems of perennials are cut to a hand's height, and anything diseased goes off site rather than onto the compost heap.

          ## Mulching and feeding

          A thick layer of our own compost goes on every bed once the ground is wet and before it freezes. It keeps the roots warm, holds the moisture in and feeds the soil slowly through the winter. Roses get a handful of feed in late February, and young hedges a little more, so they start the spring strong. We never mulch over crowns that would sit wet, such as delphiniums, and we leave a ring of bare soil around the stems of shrubs.

          ## Protecting tender plants

          Tender plants in pots move against a south wall or into a cold greenhouse, wrapped in fleece on the coldest nights. Tree ferns get their crowns stuffed with straw, and banana stems are wrapped in hessian. Nothing is wrapped in plastic, which traps damp and does more harm than the frost would.

          ## Booking a visit

          We look after gardens across Northumberland, Durham and the Tyne Valley from November to February. If you would like a winter visit, tell us about your garden and we will arrange a first walk round before the cold sets in.
        </draft>
        MD;

    private static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', type: 'bard', meta: [HeadingLevels::META => [2, 3]]),
        ]);
    }

    private static function index(): MemoryEntryIndex
    {
        $index = new MemoryEntryIndex;
        $index->put(IndexRow::make(new EntryRef('services', 'plans', 'default'), IndexScope::Link, 'Planting plans', '/garden-services/planting-plans', 'A planting plan for every border: what to grow, where, and how to keep it looking right through the year.', 'Garden services', link: 'entry::plans', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('pages', 'contact', 'default'), IndexScope::Link, 'Contact us', '/contact', 'Tell us about your garden and book a first visit from our team in Northumberland.', 'Pages', key: true, link: 'entry::contact', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('journal', 'october', 'default'), IndexScope::Full, 'What to do in the garden in October', '/journal/october', 'Leave seed heads standing, plant bulbs and mulch the borders before winter.', 'Journal', link: 'entry::october', locale: 'en'));
        $index->put(IndexRow::make(new EntryRef('journal', 'summer', 'default'), IndexScope::Full, 'Summer watering', '/journal/summer-watering', 'How often to water borders and pots in a hot summer.', 'Journal', link: 'entry::summer', locale: 'en'));

        return $index;
    }

    private static function site(?MemoryEntryIndex $index = null): LayoutContext
    {
        return new LayoutContext(self::schema(), links: new LinkContext($index ?? self::index(), new StatamicLinks, 'journal', 'default', null, new ContentKind('journal', 'Journal'), 'Plain and warm.', 'en_GB'));
    }

    /**
     * @return array{0: Session, 1: SessionLayouts, 2: LayoutContext, 3: list<string>}
     */
    private function firstDraft(?LayoutContext $site = null): array
    {
        $session = Session::start(Format::Statamic, 'journal', ['brief' => 'Winter garden care, for owners of established gardens.']);
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter garden care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());
        $site ??= self::site();
        $progress = [];

        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site, function (string $stage) use (&$progress) {
            $progress[] = $stage;
        });

        return [$session, $layouts, $site, $progress];
    }

    /** The unit holding some words, as the model is shown it. */
    private static function unitWith(Session $session, string $words): string
    {
        foreach (Units::fromDraft(Draft::parse((string) $session->draft), self::schema())->restore($session->units)->all() as $unit) {
            if (str_contains($unit->markdown, $words)) {
                return $unit->id;
            }
        }

        return 'u0';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $input = 100, int $output = 50): TextResponse
    {
        return self::reply((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $input, $output);
    }

    private function scriptLinks(): void
    {
        $this->fake->respond('writer', self::reply(self::WRITER, 900, 700));
        // Units, as SeoRequest numbers them: u1 the title, u2 the lead, u3 "Cutting back"… u6 "Booking a visit".
        $this->fake->respond('seo-editor', self::json(['notes' => 'Winter care for established gardens; plans and contact fit.', 'links' => [
            ['unit' => 'u3', 'exact' => 'planting plan we drew for you', 'prefix' => '', 'target' => 'e2', 'hint' => '', 'why' => 'The sentence is about following a plan.'],
            ['unit' => 'u6', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e3', 'hint' => '', 'why' => 'An invitation to get in touch.'],
            ['unit' => 'u4', 'exact' => 'click here', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'Vague.'],
        ]], 1200, 300));
        $this->fake->respond('seo-verifier', self::json(['verdicts' => [
            ['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.'],
            ['notes' => 'Right page.', 'id' => 'l2', 'verdict' => 'keep', 'reason' => 'Fits.'],
        ]], 600, 100));
        $this->fake->respondStructured('layout-planner', ['plans' => []]);
    }

    public function test_a_first_draft_is_linked_between_the_writer_and_the_planner(): void
    {
        $this->scriptLinks();
        [$session, $layouts, $site, $progress] = $this->firstDraft();

        $this->assertSame(['writer', 'seo-editor', 'seo-verifier', 'layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()));
        $this->assertSame([SeoPass::CHECKING, SessionLayouts::PLANNING], $progress);

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('If you have a [planting plan we drew for you](statamic://entry::plans), we follow it', $body);
        $this->assertStringContainsString('If you would like a winter visit, [tell us about your garden](statamic://entry::contact) and we will', $body);
        $this->assertStringNotContainsString('[click here]', $body);

        $state = SeoState::of($session);
        $this->assertSame(['statamic://entry::plans', 'statamic://entry::contact'], array_column($state->links, 'href'));
        $this->assertSame(['Planting plans', 'Contact us'], array_column($state->links, 'title'));
        $this->assertSame(['key' => 'seo.notice.links', 'params' => ['count' => 2, 'titles' => 'Planting plans, Contact us']], $state->notice);
        $this->assertSame('I linked to 2 of your pages: Planting plans, Contact us.', $state->message()?->english());

        // Units are cut from the linked text: the planner and every layout carry the links.
        $this->assertStringContainsString('[planting plan we drew for you](statamic://entry::plans)', (string) $layouts->draftData($session, $site)['body']);
        $this->assertSame(900 + 1200 + 600 + 100, $session->usage['input'], 'The writer, both SEO calls and the planner are counted.');
    }

    public function test_the_model_is_shown_the_units_and_the_candidates_and_held_to_them(): void
    {
        $this->scriptLinks();
        $this->firstDraft();

        $request = $this->fake->prompted('seo-editor')[0];
        $this->assertStringContainsString('about 320 words', $request->prompt);
        $this->assertStringContainsString('Add at most 2, and fewer', $request->prompt, 'About one per 250 words, and at least two.');
        $this->assertStringContainsString('[u1] (text, no links here)', $request->prompt);
        $this->assertStringContainsString('[u6] (section, links allowed)', $request->prompt);
        $this->assertStringContainsString("e1. What to do in the garden in October (Journal) · /journal/october\n    Leave seed heads standing", $request->prompt);
        $this->assertStringContainsString('e2. Planting plans (Garden services) · /garden-services/planting-plans', $request->prompt);
        $this->assertStringContainsString('Write `notes` and each `why` in English.', $request->prompt);
        $this->assertStringNotContainsString('Summer watering', $request->prompt, 'Below the floor: not a candidate.');
        $this->assertSame(['u2', 'u3', 'u4', 'u5', 'u6'], $request->schema?->schema['properties']['links']['items']['properties']['unit']['enum']);
        $this->assertSame(['e1', 'e2', 'e3', ''], $request->schema?->schema['properties']['links']['items']['properties']['target']['enum']);
        $this->assertStringContainsString('Plain and warm.', $request->instructions, 'The voice guide is in the cached instructions.');
        $this->assertStringNotContainsString('Winter garden care', $request->instructions, 'Nothing about the page is in the instructions.');

        $verifier = $this->fake->prompted('seo-verifier')[0];
        $this->assertStringContainsString('l1. The words “planting plan we drew for you”, in:', $verifier->prompt);
        $this->assertStringContainsString('If you have a ⟦planting plan we drew for you⟧, we follow it', $verifier->prompt);
        $this->assertStringContainsString('Under the heading: Cutting back', $verifier->prompt);
        $this->assertStringContainsString('They would link to: Contact us (Pages), /contact', $verifier->prompt);
        $this->assertSame(['l1', 'l2'], $verifier->schema?->schema['properties']['verdicts']['items']['properties']['id']['enum']);
    }

    public function test_the_seo_requests_are_pinned(): void
    {
        $this->scriptLinks();
        $this->firstDraft();

        foreach (['seo-editor' => [6000, 'high'], 'seo-verifier' => [4000, 'high']] as $agent => [$tokens, $effort]) {
            $record = RequestLog::records($this->fake->prompted($agent))[0];
            $path = dirname(__DIR__)."/Fixtures/seo/{$agent}-request.json";
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
                @mkdir(dirname($path), 0777, true);
                file_put_contents($path, $json);
            }

            $this->assertSame((string) file_get_contents($path), $json, 'Write the fixture with GHOSTWRITER_UPDATE_FIXTURES=1.');
            $this->assertSame([$tokens, $effort], [$record['maxTokens'], $record['effort']]);
            $this->assertTrue(Agents::cachesInstructions($agent));
            $this->assertSame(Agents::WRITING, Agents::tier($agent), 'The writing tier: a wrong link costs the editor more than the tokens.');
        }
    }

    public function test_a_link_the_verifier_drops_is_not_made(): void
    {
        $this->scriptLinks();
        $this->fake->reset('seo-verifier');
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [
            ['notes' => '…', 'id' => 'l1', 'verdict' => 'drop', 'reason' => 'Not about plans.'],
            ['notes' => '…', 'id' => 'l2', 'verdict' => 'keep', 'reason' => 'Fits.'],
        ]]);
        [$session] = $this->firstDraft();

        $this->assertSame(['statamic://entry::contact'], array_column(SeoState::of($session)->links, 'href'));
        $this->assertStringNotContainsString('entry::plans', (string) $session->draft);
        $this->assertSame('seo.notice.links-one', SeoState::of($session)->notice['key'] ?? null);
    }

    public function test_a_failed_verifier_keeps_what_passed_the_checks(): void
    {
        $this->scriptLinks();
        $this->fake->reset('seo-verifier');
        $this->fake->respond('seo-verifier', fn () => throw new ProviderException('The provider is busy.', 'fake'));
        [$session] = $this->firstDraft();

        $this->assertCount(2, SeoState::of($session)->links);
        $this->assertNotSame([], array_filter($this->logs, fn (array $log) => str_contains($log['message'], 'link verifier failed')));
    }

    public function test_a_failed_editor_call_leaves_the_draft_unlinked_and_carries_on(): void
    {
        $this->scriptLinks();
        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', fn () => throw new ProviderException('The provider is busy.', 'fake'));
        [$session] = $this->firstDraft();

        $this->assertStringNotContainsString('statamic://', (string) $session->draft);
        $this->assertSame([], SeoState::of($session)->links);
        $this->assertNotNull(SeoState::of($session)->checked);
        $this->assertSame(['writer', 'seo-editor', 'layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()));
    }

    public function test_no_candidates_means_no_call_and_a_notice(): void
    {
        $this->scriptLinks();
        [$session] = $this->firstDraft(self::site(new MemoryEntryIndex));

        $this->assertSame(['writer', 'layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()));
        $this->assertSame('I didn\'t find pages close enough to link to.', SeoState::of($session)->message()?->english());
    }

    public function test_without_a_link_context_nothing_changes(): void
    {
        $this->scriptLinks();
        [$session, , , $progress] = $this->firstDraft(new LayoutContext(self::schema()));

        $this->assertSame(['writer', 'layout-planner'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()));
        $this->assertSame([], $session->seo);
        $this->assertSame([SessionLayouts::PLANNING], $progress);
    }

    public function test_later_turns_and_edits_make_no_call_and_keep_the_links(): void
    {
        $this->scriptLinks();
        [$session, $layouts, $site] = $this->firstDraft();
        $this->fake->reset();

        // An edit by hand.
        $before = $session->draft;
        $session->draft = str_replace('Most borders', 'Most beds', (string) $session->draft);
        $layouts->afterEdit($session, $before, $site);

        // A later writer turn that keeps the links.
        $this->fake->respond('writer', self::reply(str_replace(['Winter is when', 'planting plan we drew for you,', 'tell us about your garden and'], ['Winter is the season', '[planting plan we drew for you](statamic://entry::plans),', '[tell us about your garden](statamic://entry::contact) and'], self::WRITER)));
        $studio = $this->studio();
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter garden care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);

        $this->assertSame(['writer'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()), 'No SEO call on an edit or a later turn.');
        $this->assertStringContainsString('[tell us about your garden](statamic://entry::contact)', (string) $session->draft);
        $this->assertCount(2, SeoState::of($session)->links);
    }

    public function test_link_guard_turns_an_address_the_writer_made_up_into_a_marker(): void
    {
        $this->scriptLinks();
        $this->fake->reset('writer');
        $this->fake->respond('writer', self::reply(str_replace(['tell us about your garden and', 'Seed heads'], ['[tell us about your garden](https://northfold.test/contact) and', '[Seed heads](https://www.rhs.org.uk/seed-heads)'], self::WRITER)));
        $session = Session::start(Format::Statamic, 'journal', ['brief' => 'See https://www.rhs.org.uk/seed-heads for seed heads.']);
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter garden care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $response = $studio->write($conversation, $context);
        $session->answer($response->reply, $response->document);
        $this->fake->reset('seo-editor');
        $this->fake->respondStructured('seo-editor', ['notes' => 'Nothing more.', 'links' => []]);
        $layouts->afterWriter($session, null, $response, $conversation, $context, self::site());

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('[tell us about your garden](#gw-link:tell-us-about-your-garden)', $body);
        $this->assertStringContainsString('[Seed heads](https://www.rhs.org.uk/seed-heads)', $body, 'An outside address from the brief stays.');
        $this->assertSame([], SeoState::of($session)->links);
    }

    public function test_remove_link_keeps_the_words_and_the_writer_cant_put_it_back(): void
    {
        $this->scriptLinks();
        [$session, $layouts, $site] = $this->firstDraft();

        $this->assertTrue($layouts->removeLink($session, 'statamic://entry::contact', $site));
        $this->assertFalse($layouts->removeLink($session, 'statamic://entry::nothing', $site));

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('If you would like a winter visit, tell us about your garden and we will', $body);
        $this->assertSame(['statamic://entry::plans'], array_column(SeoState::of($session)->links, 'href'));
        $this->assertSame(['statamic://entry::contact'], SeoState::of($session)->removed);
        $this->assertStringNotContainsString('entry::contact', (string) json_encode($layouts->draftData($session, $site)), 'The layouts follow.');

        // The writer puts it back: it's taken out again, words kept.
        $this->fake->reset('writer');
        $this->fake->respond('writer', self::reply(str_replace(['planting plan we drew for you,', 'tell us about your garden and'], ['[planting plan we drew for you](statamic://entry::plans),', '[tell us about your garden](statamic://entry::contact) and'], self::WRITER)));
        $studio = $this->studio();
        $conversation = new Conversation([['role' => 'user', 'content' => 'Again.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('winter visit, tell us about your garden and', $body);
        $this->assertStringContainsString('[planting plan we drew for you](statamic://entry::plans)', $body);
    }

    /** The writer's own links: one to a real page, one made up, and two it left for the editor to choose. */
    private static function writerWithLinks(): string
    {
        return str_replace(
            ['Seed heads of sedum, teasel and grasses stay standing', 'Tree ferns get', 'tell us about your garden and we will', 'Roses get a handful'],
            ['[Seed heads of sedum](statamic://entry::october), teasel and grasses stay standing', '[Tree ferns](#gw-link:tree-fern-guide) get', '[get in touch](#gw-link:contact-page) and we will', '[Roses](statamic://entry::gone) get a handful'],
            self::WRITER,
        );
    }

    private function scriptWriterLinks(): void
    {
        $this->fake->respond('writer', self::reply(self::writerWithLinks(), 900, 700));
        // e1 Planting plans, e2 Contact us: October is linked already.
        $this->fake->respond('seo-editor', self::json(['notes' => 'Winter care; plans fit, and the call to get in touch suits Contact.', 'links' => [
            ['unit' => 'u3', 'exact' => 'planting plan we drew for you', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'The sentence is about following a plan.'],
        ], 'markers' => [
            ['marker' => 'm1', 'target' => '', 'why' => 'Nothing about roses on the list.'],
            ['marker' => 'm2', 'target' => '', 'why' => 'No guide to tree ferns on the list.'],
            ['marker' => 'm3', 'target' => 'e2', 'why' => 'A call to get in touch.'],
            ['marker' => 'm3', 'target' => 'e1', 'why' => 'A second answer for the same marker.'],
        ]], 1200, 300));
        $this->fake->respond('seo-verifier', self::json(['verdicts' => [
            ['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.'],
            ['notes' => 'Right page.', 'id' => 'm3', 'verdict' => 'keep', 'reason' => 'Fits.'],
        ]], 600, 100));
        $this->fake->respondStructured('layout-planner', ['plans' => []]);
    }

    public function test_the_writers_links_to_real_pages_stay_and_count_and_its_markers_dont(): void
    {
        $this->scriptWriterLinks();
        [$session] = $this->firstDraft();

        $body = (string) Draft::parse((string) $session->draft)->data['body'];
        $this->assertStringContainsString('[Seed heads of sedum](statamic://entry::october), teasel', $body, 'A real, published page: kept (decision 22).');
        $this->assertStringContainsString('[Roses](#gw-link:roses) get a handful', $body, 'No such page: a marker, as before.');
        $this->assertStringContainsString('[get in touch](#gw-link:contact-page) and we will', $body, 'The writer\'s markers stay markers.');
        $this->assertStringContainsString('[planting plan we drew for you](statamic://entry::plans)', $body);

        $request = $this->fake->prompted('seo-editor')[0];
        $this->assertStringContainsString('Links it has already: 1. Add at most 1, and fewer', $request->prompt, 'The kept link counts; the three markers don\'t (decision 23).');
        $this->assertStringContainsString("## Links the writer left for the editor to choose\n\nFor each, the site's page the editor most likely means, or none.\nm1. “Roses” in u4 (the writer's note: \"roses\")\nm2. “Tree ferns” in u5 (the writer's note: \"tree fern guide\")\nm3. “get in touch” in u6 (the writer's note: \"contact page\")", $request->prompt);
        $this->assertStringNotContainsString('What to do in the garden in October', $request->prompt, 'A page linked already isn\'t a candidate.');
        $markers = $request->schema?->schema['properties']['markers'];
        $this->assertSame(['m1', 'm2', 'm3'], $markers['items']['properties']['marker']['enum'], 'The one LinkGuard made and the writer\'s two.');
        $this->assertSame(['e1', 'e2', ''], $markers['items']['properties']['target']['enum']);
        $this->assertSame(3, $markers['maxItems']);
    }

    public function test_the_seo_requests_with_the_writers_markers_are_pinned(): void
    {
        $this->scriptWriterLinks();
        $this->firstDraft();

        foreach (['seo-editor', 'seo-verifier'] as $agent) {
            $record = RequestLog::records($this->fake->prompted($agent))[0];
            $path = dirname(__DIR__)."/Fixtures/seo/{$agent}-markers-request.json";
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
                file_put_contents($path, $json);
            }

            $this->assertSame((string) file_get_contents($path), $json, 'Write the fixture with GHOSTWRITER_UPDATE_FIXTURES=1.');
        }
    }

    public function test_a_writers_marker_gets_a_suggested_page_the_verifier_checked(): void
    {
        $this->scriptWriterLinks();
        [$session] = $this->firstDraft();

        $verifier = $this->fake->prompted('seo-verifier')[0];
        $this->assertSame(['l1', 'm3'], $verifier->schema?->schema['properties']['verdicts']['items']['properties']['id']['enum'], 'Only a suggestion with a page is checked, once.');
        $this->assertStringContainsString("m3. The words “get in touch”, in:\n    We look after gardens", $verifier->prompt);
        $this->assertStringContainsString('If you would like a winter visit, ⟦get in touch⟧ and we will', $verifier->prompt);
        $this->assertStringContainsString('The page suggested: Contact us (Pages), /contact', $verifier->prompt);

        $state = SeoState::of($session);
        $this->assertSame([['hint' => 'contact-page', 'words' => 'get in touch', 'id' => 'contact', 'title' => 'Contact us', 'type' => 'Pages', 'url' => '/contact', 'href' => 'statamic://entry::contact', 'why' => 'A call to get in touch.']], array_values(array_filter($state->suggested, fn (array $s) => $s['hint'] === 'contact-page')));
        $this->assertSame('Contact us', $state->suggestion('contact page')['title'] ?? null, 'Found by the hint as a chip shows it.');
        $this->assertNull($state->suggestion('tree-fern-guide'), 'Nothing fits: no suggestion.');
        $this->assertStringContainsString('[get in touch](#gw-link:contact-page)', (string) $session->draft, 'Never resolved by the pass.');
        $this->assertSame(['statamic://entry::plans'], array_column($state->links, 'href'));
    }

    public function test_a_suggestion_the_verifier_drops_is_not_kept(): void
    {
        $this->scriptWriterLinks();
        $this->fake->reset('seo-verifier');
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [
            ['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.'],
            ['notes' => '…', 'id' => 'm3', 'verdict' => 'drop', 'reason' => 'Too vague.'],
        ]]);
        [$session] = $this->firstDraft();

        $this->assertNull(SeoState::of($session)->suggestion('contact-page'));
        $this->assertCount(1, SeoState::of($session)->links);
    }

    public function test_a_draft_with_enough_links_still_gets_suggestions_for_its_markers(): void
    {
        $links = str_replace(
            ['Winter is when a garden', 'Most borders need', 'Old stems of perennials', 'A thick layer', 'Nothing is wrapped', 'tell us about your garden and we will'],
            ['[Winter](statamic://entry::october) is when a garden', '[Most borders](statamic://entry::plans) need', '[Old stems](statamic://entry::summer) of perennials', 'A [thick layer](statamic://entry::october) of', '[Nothing](statamic://entry::plans) is wrapped', '[get in touch](#gw-link:contact-page) and we will'],
            self::WRITER,
        );
        $this->fake->respond('writer', self::reply($links));
        $this->fake->respondStructured('seo-editor', ['notes' => 'Enough links.', 'links' => [], 'markers' => [['marker' => 'm1', 'target' => 'e1', 'why' => 'A call to get in touch.']]]);
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'm1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
        $this->fake->respondStructured('layout-planner', ['plans' => []]);
        [$session] = $this->firstDraft();

        $request = $this->fake->prompted('seo-editor')[0];
        $this->assertStringContainsString('Links it has already: 5. It has enough: add none', $request->prompt);
        $this->assertSame(0, $request->schema?->schema['properties']['links']['maxItems']);
        $this->assertSame('Contact us', SeoState::of($session)->suggestion('contact-page')['title'] ?? null);
        $this->assertNull(SeoState::of($session)->notice, 'No links were wanted: nothing to say about them.');
    }

    public function test_a_short_draft_or_one_with_enough_links_is_not_sent(): void
    {
        $this->assertSame(2, SeoLinks::target(100));
        $this->assertSame(2, SeoLinks::target(600));
        $this->assertSame(4, SeoLinks::target(1000));
        $this->assertSame(5, SeoLinks::target(3000));

        $this->fake->respond('writer', self::reply("<reply>Short.</reply><draft>\ntitle: Winter garden care\nbody: |\n  A short note on winter care for the garden, with a planting plan.\n</draft>"));
        $this->fake->respondStructured('layout-planner', ['plans' => []]);
        [$session] = $this->firstDraft();

        $this->assertSame(['writer'], array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests()), 'Too short to link, and to lay out another way.');
        $this->assertNull(SeoState::of($session)->notice, 'Nothing to say about a page too short to link.');
        $this->assertNotNull(SeoState::of($session)->checked);
        $this->assertSame([], Units::fromDraft(Draft::parse((string) $session->draft), self::schema())->at(FieldPath::of('nothing')));
    }
}
