<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaAction;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\PlainSeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchFields;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchSection;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoMetaCheck;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SlugContext;
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

/**
 * The search title, description and address in the writing pipeline (SEO
 * layer §5.1, §5.2, §9, §10, row 5): written by the first draft's
 * `seo-editor` call beside the links, checked in code, kept on the
 * session; written again only when a writer's turn changed the page
 * enough and nobody edited them; Try again; the address from the title.
 */
final class SeoMetaTest extends StudioTestCase
{
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

    private const DESCRIPTION = 'Winter care for established gardens: what we cut back, how we mulch and feed, and how tender plants are kept safe until the spring.';

    private const LONG_TITLE = 'Winter garden care for established borders, from cutting back to mulching';

    private static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', type: 'bard', meta: [HeadingLevels::META => [2, 3]]),
            new Field('seo_title', Kind::Text, 'SEO title'),
            new Field('meta_description', Kind::LongText, 'Meta description', meta: ['character_limit' => 160]),
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

    private static function meta(bool $newEntry = true, array $values = [], ?SeoProvenance $provenance = null): MetaContext
    {
        return new MetaContext(new PlainSeoFields, self::schema(), new EntryData($values, group: 'journal'), $newEntry, $provenance ?? new SeoProvenance, new SlugContext(true, dated: true, taken: ['winter-garden-care'], base: 'northfold.garden/journal/'), new ContentKind('journal', 'Journal'), 'Plain and warm.', 'en_GB');
    }

    private static function site(bool $links = true, ?MetaContext $meta = null): LayoutContext
    {
        return new LayoutContext(self::schema(), links: $links ? new LinkContext(self::index(), new StatamicLinks, 'journal', 'default', null, new ContentKind('journal', 'Journal'), 'Plain and warm.', 'en_GB') : null, meta: $meta ?? self::meta());
    }

    /**
     * @return array{0: Session, 1: SessionLayouts, 2: LayoutContext}
     */
    private function firstDraft(?LayoutContext $site = null, ?string $writer = null): array
    {
        $session = Session::start(Format::Statamic, 'journal', ['brief' => 'Winter garden care, for owners of established gardens.']);
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter garden care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());
        $site ??= self::site();

        if ($writer !== null) {
            $this->fake->reset('writer');
            $this->fake->respond('writer', self::reply($writer, 900, 700));
        }

        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);

        return [$session, $layouts, $site];
    }

    /**
     * A later writer turn with this draft.
     */
    private function turn(Session $session, SessionLayouts $layouts, LayoutContext $site, string $writer): void
    {
        $this->fake->reset('writer');
        $this->fake->respond('writer', self::reply($writer));
        $studio = $this->studio();
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter garden care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema(self::schema()), '');
        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data, int $input = 100, int $output = 50): TextResponse
    {
        return self::reply((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $input, $output);
    }

    /**
     * @return array<string, mixed>
     */
    private static function editor(string $description = self::DESCRIPTION, string $title = '', array $links = []): array
    {
        return ['notes' => 'Winter care for established gardens.', 'links' => $links, 'markers' => [], 'title' => $title, 'description' => $description];
    }

    private function script(): void
    {
        $this->fake->respond('writer', self::reply(self::WRITER, 900, 700));
        $this->fake->respond('seo-editor', self::json(self::editor(links: [
            ['unit' => 'u6', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e3', 'hint' => '', 'why' => 'An invitation to get in touch.'],
        ]), 1200, 300));
        $this->fake->respondStructured('seo-verifier', ['verdicts' => [['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
        $this->fake->respondStructured('layout-planner', ['plans' => []]);
    }

    /** @return list<string> */
    private function agents(): array
    {
        return array_map(fn (TextRequest $request) => $request->agent, $this->fake->requests());
    }

    public function test_the_first_drafts_one_call_writes_the_links_and_the_description(): void
    {
        $this->script();
        [$session] = $this->firstDraft();

        $this->assertSame(['writer', 'seo-editor', 'seo-verifier', 'layout-planner'], $this->agents(), 'No extra call for the description.');
        $state = SeoState::of($session);
        $this->assertSame(self::DESCRIPTION, $state->meta->description);
        $this->assertSame('', $state->meta->title, 'The page title fits: no SEO title of its own (decision 12).');
        $this->assertSame('winter-garden-care-2', $state->meta->slug, 'From the title, unique in the collection.');
        $this->assertCount(1, $state->links);
        $this->assertNotNull($state->meta->checked);

        $prompt = $this->fake->prompted('seo-editor')[0]->prompt;
        $this->assertStringContainsString("## Search title and description\n\n- `title`: give `\"\"`.", $prompt);
        $this->assertStringContainsString('- `description`: 120 to 155 characters', $prompt);
        $this->assertStringContainsString('Anything in a `[[ask: …]]` or `[[check: …]]` marker isn\'t confirmed yet', $prompt);
        $this->assertStringContainsString('and the title and description in English.', $prompt);
        $schema = $this->fake->prompted('seo-editor')[0]->schema?->schema;
        $this->assertSame(['notes', 'links', 'markers', 'title', 'description'], $schema['required'] ?? null);
    }

    public function test_the_meta_request_is_pinned(): void
    {
        $this->script();
        $this->firstDraft(self::site(links: false));

        $record = RequestLog::records($this->fake->prompted('seo-editor'))[0];
        $path = dirname(__DIR__).'/Fixtures/seo/seo-editor-meta-request.json';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            file_put_contents($path, $json);
        }

        $this->assertSame((string) file_get_contents($path), $json, 'Write the fixture with GHOSTWRITER_UPDATE_FIXTURES=1.');
    }

    public function test_without_links_the_call_is_for_the_meta_alone(): void
    {
        $this->script();
        [$session] = $this->firstDraft(self::site(links: false));

        $this->assertSame(['writer', 'seo-editor', 'layout-planner'], $this->agents());
        $this->assertStringContainsString('No links are wanted this time', $this->fake->prompted('seo-editor')[0]->prompt);
        $this->assertSame(self::DESCRIPTION, SeoState::of($session)->meta->description);
        $this->assertSame([], SeoState::of($session)->links);
    }

    public function test_a_long_page_title_gets_a_search_title_of_its_own(): void
    {
        $this->script();
        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', self::json(self::editor(title: 'Winter garden care for established borders')));
        [$session] = $this->firstDraft(self::site(links: false), str_replace('title: Winter garden care', 'title: '.self::LONG_TITLE, self::WRITER));

        $this->assertStringContainsString('- `title`: 30 to 52 characters. The page\'s own title, "'.self::LONG_TITLE.'", is too long', $this->fake->prompted('seo-editor')[0]->prompt);
        $this->assertSame('Winter garden care for established borders', SeoState::of($session)->meta->title);
    }

    public function test_an_invented_fact_is_asked_for_again_once_then_dropped(): void
    {
        $this->script();
        $this->fake->reset('seo-editor');
        $bad = 'Twelve years of winter care for established gardens in Cumbria: what we cut back, how we mulch and feed, and how tender plants are kept safe.';
        $this->fake->respond('seo-editor', self::json(self::editor($bad)), self::json(self::editor($bad)));
        [$session] = $this->firstDraft(self::site(links: false));

        $this->assertCount(2, $this->fake->prompted('seo-editor'), 'Asked once more, with the problem quoted.');
        $this->assertStringContainsString('Cumbria', $this->fake->prompted('seo-editor')[1]->prompt);
        $meta = SeoState::of($session)->meta;
        $this->assertSame('', $meta->description, 'Dropped, not repaired.');
        $this->assertContains(SeoMetaCheck::UNSOURCED, $meta->dropped['description']);

        $section = (new SearchSection)->of($session, self::meta());
        $this->assertSame('seo.search.description-dropped', $section['description']['note']['key']);
    }

    public function test_a_retry_that_fixes_it_is_used(): void
    {
        $this->script();
        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', self::json(self::editor('Too short.')), self::json(self::editor()));
        [$session] = $this->firstDraft(self::site(links: false));

        $this->assertSame(self::DESCRIPTION, SeoState::of($session)->meta->description);
    }

    public function test_a_person_s_description_on_an_existing_entry_is_written_for_a_suggestion_only(): void
    {
        $this->script();
        $site = self::site(links: false, meta: self::meta(false, ['meta_description' => 'Our own words about winter care, written by the editor.']));
        [$session] = $this->firstDraft($site);

        $this->assertSame(self::DESCRIPTION, SeoState::of($session)->meta->description);
        $this->assertSame('suggest', (new SearchSection)->of($session, $site->meta)['description']['action']);
        $this->assertSame('seo.search.description-stays', (new SearchSection)->of($session, $site->meta)['description']['note']['key']);
    }

    public function test_nothing_to_write_means_no_call(): void
    {
        $this->script();
        $fits = 'Our own words about winter care, written by the editor for this page: cutting back, mulching, feeding and keeping tender plants safe.';
        $site = self::site(links: false, meta: self::meta(false, ['meta_description' => $fits], (new SeoProvenance)));
        // A description that inherits and fits would be left; a plain one of a person's is suggested, so use no SEO fields at all.
        $none = new LayoutContext(new Schema([new Field('title', Kind::Text, 'Title'), new Field('body', Kind::RichText, 'Body', type: 'bard', meta: [HeadingLevels::META => [2, 3]])]), meta: new MetaContext(new PlainSeoFields, new Schema([new Field('title', Kind::Text, 'Title')]), new EntryData([]), slug: new SlugContext(true)));
        [$session] = $this->firstDraft($none);

        $this->assertSame(['writer', 'layout-planner'], $this->agents());
        $this->assertSame('winter-garden-care', SeoState::of($session)->meta->slug, 'The address is made all the same.');
        $this->assertNotNull($site->meta);
    }

    public function test_a_later_turn_writes_them_again_only_when_the_page_changed_enough(): void
    {
        $this->script();
        [$session, $layouts, $site] = $this->firstDraft(self::site(links: false));
        $this->fake->reset();
        $this->fake->respondStructured('seo-editor', self::editor('Winter care for established borders: cutting back, mulching and feeding, and how tender plants are kept safe until the spring comes.'));

        $this->turn($session, $layouts, $site, str_replace('Most borders need less', 'Most beds need less', self::WRITER));
        $this->assertSame(['writer'], $this->agents(), 'A small change: no call.');

        $this->turn($session, $layouts, $site, str_replace('title: Winter garden care', 'title: Winter care for borders', self::WRITER));
        $this->assertSame(['writer', 'seo-editor'], $this->agents(), 'The title changed: the meta follows.');
        $this->assertStringContainsString('No links are wanted this time', $this->fake->prompted('seo-editor')[0]->prompt);
        $this->assertStringStartsWith('Winter care for established borders', SeoState::of($session)->meta->description);
        $this->assertSame('winter-care-borders', SeoState::of($session)->meta->slug, 'The address follows the title.');
    }

    public function test_an_editors_text_is_never_rewritten_by_a_turn(): void
    {
        $this->script();
        [$session, $layouts, $site] = $this->firstDraft(self::site(links: false));
        $pass = new SeoPass(studio: $this->studio());
        $pass->editMeta($session, SeoField::DESCRIPTION, 'Mine.');
        $pass->editMeta($session, 'slug', 'Winter Care');
        $this->fake->reset();

        $this->turn($session, $layouts, $site, str_replace('title: Winter garden care', 'title: Winter care for borders', self::WRITER));

        $this->assertSame(['writer'], $this->agents(), 'Nothing left to write: no call.');
        $this->assertSame('Mine.', SeoState::of($session)->meta->description);
        $this->assertSame('winter-care', SeoState::of($session)->meta->slug, 'A typed address stays.');
        $this->assertSame('seo.search.edited', (new SearchSection)->of($session, $site->meta)['description']['note']['key']);
    }

    public function test_try_again_asks_once_for_another_even_over_the_editors_own(): void
    {
        $this->script();
        [$session, , $site] = $this->firstDraft(self::site(links: false));
        $pass = new SeoPass(studio: $this->studio());
        $pass->editMeta($session, SeoField::DESCRIPTION, 'Mine, which I want replaced now that I think about it more.');
        $this->fake->reset();
        $other = 'From November to February we cut back, mulch and feed established gardens, and keep tender plants safe from the frost until the spring.';
        $this->fake->respondStructured('seo-editor', self::editor($other));
        $input = $session->usage['input'];

        $usage = $pass->retryMeta($session, $site);

        $this->assertSame(['seo-editor'], $this->agents());
        $this->assertStringContainsString('The editor asked for another. Write it differently from:', $this->fake->prompted('seo-editor')[0]->prompt);
        $this->assertStringContainsString('Mine, which I want replaced', $this->fake->prompted('seo-editor')[0]->prompt);
        $this->assertSame($other, SeoState::of($session)->meta->description);
        $this->assertFalse(SeoState::of($session)->meta->edited(SeoField::DESCRIPTION), 'Ghostwriter\'s again.');
        $this->assertGreaterThan(0, $usage->input);
        $this->assertSame($input + $usage->input, $session->usage['input']);
    }

    public function test_try_again_fails_loudly(): void
    {
        $this->script();
        [$session, , $site] = $this->firstDraft(self::site(links: false));
        $this->fake->reset('seo-editor');
        $this->fake->respond('seo-editor', fn () => throw new ProviderException('The provider is busy.', 'fake'));

        $this->expectException(ProviderException::class);
        (new SeoPass(studio: $this->studio()))->retryMeta($session, $site);
    }

    public function test_the_search_section(): void
    {
        $this->script();
        [$session, , $site] = $this->firstDraft(self::site(links: false));
        $section = (new SearchSection)->of($session, $site->meta);

        $this->assertTrue($section['fields']);
        $this->assertSame(['own' => false, 'pageTitle' => 'Winter garden care', 'length' => 18, 'limit' => 60, 'min' => 30, 'max' => 52, 'key' => 'seo.search.title-fits'], [
            'own' => $section['title']['own'], 'pageTitle' => $section['title']['pageTitle'], 'length' => $section['title']['length'],
            'limit' => $section['title']['limit'], 'min' => $section['title']['min'], 'max' => $section['title']['max'], 'key' => $section['title']['note']['key'],
        ]);
        $this->assertSame([self::DESCRIPTION, mb_strlen(self::DESCRIPTION), 160, 120, 155, 'write', 'seo.search.description-new'], [
            $section['description']['text'], $section['description']['length'], $section['description']['limit'], $section['description']['min'], $section['description']['max'], $section['description']['action'], $section['description']['note']['key'],
        ]);
        $this->assertSame(['slug' => 'winter-garden-care-2', 'base' => 'northfold.garden/journal/', 'editable' => true], array_intersect_key($section['address'], ['slug' => 1, 'base' => 1, 'editable' => 1]));
        $this->assertSame('seo.search.address-new', $section['address']['note']['key']);

        $published = new MetaContext(new PlainSeoFields, self::schema(), new EntryData([]), false, slug: new SlugContext(false, current: 'winter-care'));
        $this->assertSame(['slug' => 'winter-care', 'editable' => false, 'key' => 'seo.search.address-kept'], ['slug' => (new SearchSection)->of($session, $published)['address']['slug'], 'editable' => (new SearchSection)->of($session, $published)['address']['editable'], 'key' => (new SearchSection)->of($session, $published)['address']['note']['key']]);
    }

    public function test_use_this_and_the_page_title_again(): void
    {
        $this->script();
        [$session] = $this->firstDraft(self::site(links: false));
        $pass = new SeoPass(studio: $this->studio());

        $pass->editMeta($session, SeoField::TITLE, 'Winter care for established gardens');
        $this->assertSame('Winter care for established gardens', SeoState::of($session)->meta->title);
        $this->assertSame('seo.search.edited', (new SearchSection)->of($session, self::meta())['title']['note']['key']);

        $pass->editMeta($session, SeoField::TITLE, '');
        $this->assertSame('', SeoState::of($session)->meta->title, 'Use the page title.');

        $pass->useMeta($session, SeoField::DESCRIPTION);
        $this->assertTrue(SeoState::of($session)->meta->uses(SeoField::DESCRIPTION));
        $this->assertSame(SeoState::of($session)->toArray(), SeoState::fromArray(SeoState::of($session)->toArray())->toArray(), 'It round-trips.');
    }

    public function test_a_title_that_inherits_the_page_title_can_be_given_its_own(): void
    {
        $this->script();
        [$session] = $this->firstDraft(self::site(links: false));
        $fields = new class implements SeoFields
        {
            public function __construct(public SeoSource $source = SeoSource::Field, public bool $writable = true) {}

            public function in(Schema $schema, EntryData $entry): array
            {
                return [new SeoField(FieldPath::of('seo')->with('title'), SeoField::TITLE, 'SEO title', 60, 'Winter garden care', $this->writable, $this->source === SeoSource::Field ? 'Title' : null, $this->source)];
            }

            public function noindex(Schema $schema, EntryData $entry): ?bool
            {
                return null;
            }

            public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat
            {
                return null;
            }
        };
        $context = new MetaContext($fields, self::schema(), new EntryData([], group: 'journal'), false);

        $row = (new SearchSection)->of($session, $context)['title'];
        $this->assertSame([false, 'leave', true, 'seo.search.title-fits'], [$row['own'], $row['action'], $row['editable'], $row['note']['key']], 'It fits, so it inherits; the editor can still give it its own.');

        $applied = (new SearchFields($fields, new PlainSeoWriter))->apply([], self::schema(), new EntryData([]), SeoState::of($session), false);
        $this->assertSame([], $applied->values, 'Ghostwriter leaves it by itself (decision 12).');

        (new SeoPass(studio: $this->studio()))->editMeta($session, SeoField::TITLE, 'Winter care for established gardens');
        $row = (new SearchSection)->of($session, $context)['title'];
        $this->assertSame([true, 'write', 'seo.search.edited'], [$row['own'], $row['action'], $row['note']['key']]);

        $applied = (new SearchFields($fields, new PlainSeoWriter))->apply([], self::schema(), new EntryData([]), SeoState::of($session), false);
        $this->assertSame(['seo' => ['title' => 'Winter care for established gardens']], $applied->values, 'The editor gave it its own: written.');
        $this->assertSame(MetaAction::Write, $applied->actions[SeoField::TITLE]);
        $this->assertTrue($applied->written->isEmpty(), 'The editor\'s, not Ghostwriter\'s.');

        $fields->source = SeoSource::Disabled;
        $this->assertSame([], (new SearchFields($fields, new PlainSeoWriter))->apply([], self::schema(), new EntryData([]), SeoState::of($session), false)->values, 'Switched off: never.');

        $fields->source = SeoSource::Template;
        $fields->writable = false;
        $this->assertSame([], (new SearchFields($fields, new PlainSeoWriter))->apply([], self::schema(), new EntryData([]), SeoState::of($session), false)->values, 'A template the entry can\'t override: never.');
    }
}
