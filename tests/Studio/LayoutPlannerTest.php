<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\PlanSchemaTest;
use Symfony\Component\Yaml\Yaml;

/**
 * Drafting with layouts, through FakeProvider: the first draft is the
 * writer (with extras) and then the layout planner; switching, applying,
 * editing extras and later turns call nothing more.
 */
final class LayoutPlannerTest extends StudioTestCase
{
    private const PLANS = <<<'YAML'
        <plans>
        - name: Scannable
          description: The visits as cards, then who it suits
          follows: p-1
          page_builder:
            - type: hero
              place: { heading: u3, subheading: u4, image: u5 }
            - type: stats
              place: { items: [x1.1] }
            - type: text
              place: { body: u6 }
            - type: section
              place: { heading: "u7#1" }
              children:
                - { type: card, place: { heading: "u7#2:lead", body: "u7#2:rest" } }
                - { type: card, place: { heading: "u7#3:lead", body: "u7#3:rest" } }
            - type: text
              place: { body: u8 }
            - type: cta
              place: { heading: u9, button: u10 }
        - name: Invented
          description: A carousel this site doesn't have
          page_builder:
            - type: carousel
              place: { slides: [u3, u4, u6, u7, u8, u9, u10] }
        </plans>
        YAML;

    private function draftReply(): string
    {
        return "<reply>Here is a first draft.</reply>\n<draft>\n".Yaml::dump(Northfold::blocksDraft(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."</draft>\n<extras>\n- kind: stats\n  items:\n    - text: \"4 visits a winter\"\n      value: \"4\"\n      label: visits a winter\n      source: { from: brief, quote: \"four visits between November and February\" }\n    - text: \"Trusted by 500 gardens\"\n      source: { from: brief, quote: \"four visits\" }\n</extras>";
    }

    private function site(): LayoutContext
    {
        $entries = [
            new EntryData(['title' => 'Lawn care', 'page_builder' => [['type' => 'hero'], ['type' => 'stats'], ['type' => 'text'], ['type' => 'section'], ['type' => 'cta']]], 7),
            new EntryData(['title' => 'Ponds', 'page_builder' => [['type' => 'hero'], ['type' => 'stats'], ['type' => 'text'], ['type' => 'section'], ['type' => 'cta']]], 6),
            new EntryData(['title' => 'Hedges', 'page_builder' => [['type' => 'hero'], ['type' => 'text'], ['type' => 'cta']]], 5),
        ];

        return new LayoutContext(Northfold::blocks(), null, $entries);
    }

    /**
     * @return array{0: Session, 1: SessionLayouts, 2: Conversation, 3: WriterContext}
     */
    private function firstDraft(): array
    {
        $session = Session::start(Format::Statamic, 'service', ['brief' => 'Winter care: four visits between November and February.']);
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter care: four visits between November and February.']]);
        $context = new WriterContext(new ContentKind('service', 'Service'), '', Layout::fromSchema(Northfold::blocks()), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());

        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $this->site());

        return [$session, $layouts, $conversation, $context];
    }

    public function test_a_first_draft_is_the_writer_then_the_planner_and_switching_costs_nothing(): void
    {
        $this->fake->respond('writer', self::reply($this->draftReply(), 1000, 800));
        $this->fake->respond('layout-planner', self::reply(self::PLANS, 300, 200));

        [$session, $layouts] = $this->firstDraft();

        $this->assertSame(['writer', 'layout-planner'], array_map(fn ($request) => $request->agent, $this->fake->requests()));
        $this->assertSame(['input' => 1300, 'output' => 1000], array_intersect_key($session->usage, ['input' => 0, 'output' => 0]));

        $planner = $this->sent('layout-planner');
        $this->assertSame([4000, 'low'], [$planner->resolvedMaxTokens(), $planner->resolvedEffort()?->value]);
        $this->assertStringContainsString('Propose 2 arrangements', $planner->instructions);
        $this->assertStringContainsString('u7 [section, ', $planner->prompt);
        $this->assertStringContainsString('  u7#2 paragraph (lead-in "November: Cut back"): "', $planner->prompt);
        $this->assertStringContainsString('x1.1 [stats] "4 visits a winter" (value: "4", label: "visits a winter")', $planner->prompt);
        $this->assertStringContainsString('p-1: page_builder: hero, stats, text, section, cta (used by 2 entries, e.g. Lawn care)', $planner->prompt);
        $this->assertStringContainsString('page_builder: hero [u3 u4 u5], text [u6 u7 u8], spacer [], cta [u9 u10]', $planner->prompt);
        $this->assertStringNotContainsString('Trusted', $planner->prompt, 'an unsourced extra never reaches the planner');

        $plans = $layouts->plans($session);
        $this->assertSame(['w', 'p1'], array_map(fn (Plan $plan) => $plan->id, $plans->all()), 'the invented carousel is dropped');
        $this->assertSame(['Scannable', 'The visits as cards, then who it suits', 'p-1'], [$plans->get('p1')?->name, $plans->get('p1')?->description, $plans->get('p1')?->follows]);
        $this->assertSame('p1', $plans->suggested()?->id, 'it follows the commonest of the site\'s pages');
        $this->assertSame(['x1.1'], array_keys($layouts->extras($session)->items()));

        $this->fake->reset();
        $layouts->choose($session, 'p1');
        $this->assertSame('p1', $session->plan);
        $data = $layouts->draftData($session, $this->site());
        $built = $layouts->build($session, $this->site());
        $layouts->choose($session, 'w');
        $this->assertNull($session->plan);
        $this->fake->assertNothingSent();

        $this->assertSame(['hero', 'stats', 'text', 'section', 'text', 'cta'], array_column($data['page_builder'], 'type'));
        $this->assertSame([['value' => '4', 'label' => 'visits a winter']], $data['page_builder'][1]['items']);
        $this->assertSame([], $built->notes);
        $this->assertSame(Northfold::blocksDraft(), $layouts->draftData($session, $this->site()), 'the writer\'s layout is the draft');
    }

    /** self::PLANS, as structured output sends it. */
    public static function structuredPlans(): array
    {
        $b = PlanSchemaTest::block(...);

        return ['plans' => [
            ['notes' => 'Follow p-1: hero, stats, cards.', 'name' => 'Scannable', 'description' => 'The visits as cards, then who it suits', 'follows' => 'p-1', 'fields' => [[
                'field' => 'page_builder',
                'blocks' => [
                    $b('hero', [['heading', ['u3']], ['subheading', ['u4']], ['image', ['u5']]]),
                    $b('stats', [['items', ['x1.1']]]),
                    $b('text', [['body', ['u6']]]),
                    $b('section', [['heading', ['u7#1']]], [
                        $b('card', [['heading', ['u7#2:lead']], ['body', ['u7#2:rest']]]),
                        $b('card', [['heading', ['u7#3:lead']], ['body', ['u7#3:rest']]]),
                    ]),
                    $b('text', [['body', ['u8']]]),
                    $b('cta', [['heading', ['u9']], ['button', ['u10']]]),
                ],
                'constructs' => [],
                'refs' => [],
            ]]],
            ['notes' => '', 'name' => 'Invented', 'description' => 'A carousel this site doesn\'t have', 'follows' => '', 'fields' => [[
                'field' => 'page_builder',
                'blocks' => [$b('carousel', [['slides', ['u3', 'u4', 'u6', 'u7', 'u8', 'u9', 'u10']]])],
                'constructs' => [],
                'refs' => [],
            ]]],
        ]];
    }

    public function test_a_structured_reply_gives_the_same_layouts_as_the_tagged_one(): void
    {
        $this->fake->withoutStructuredOutput()->respond('writer', self::reply($this->draftReply()));
        $this->fake->respond('layout-planner', self::reply(self::PLANS));
        [$tagged, $taggedLayouts] = $this->firstDraft();

        $this->fake->reset()->withoutStructuredOutput(false)->respond('writer', self::reply($this->draftReply()));
        $this->fake->respondStructured('layout-planner', self::structuredPlans());
        [$session, $layouts] = $this->firstDraft();

        $planner = $this->sent('layout-planner');
        $this->assertSame('plans', $planner->schema?->name);
        $this->assertStringContainsString('Your reply is JSON in the shape you are given', $planner->instructions);
        $this->assertStringNotContainsString('<plans>', $planner->instructions);
        $this->assertCount(1, $this->fake->prompted('layout-planner'));

        $this->assertSame(['w', 'p1'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()), 'the invented carousel is dropped');
        $this->assertEquals($taggedLayouts->plans($tagged)->get('p1'), $layouts->plans($session)->get('p1'));
        $this->assertSame('p1', $layouts->plans($session)->suggested()?->id);

        $layouts->choose($session, 'p1');
        $taggedLayouts->choose($tagged, 'p1');
        $this->assertSame($taggedLayouts->draftData($tagged, $this->site()), $layouts->draftData($session, $this->site()));
        $this->assertSame([], $layouts->build($session, $this->site())->notes);
    }

    public function test_a_structured_reply_cut_off_keeps_the_plans_that_closed(): void
    {
        $json = (string) json_encode(self::structuredPlans());
        $cut = substr($json, 0, (int) strpos($json, '"name":"Invented"'));
        $this->fake->respond('writer', self::reply($this->draftReply()));
        $this->fake->respond('layout-planner', self::cutOff($cut));

        [$session, $layouts] = $this->firstDraft();

        $this->assertSame(['w', 'p1'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
        $this->assertSame('Scannable', $layouts->plans($session)->get('p1')?->name);
    }

    public function test_a_later_turn_calls_only_the_writer_and_layouts_follow_the_text(): void
    {
        $this->fake->respond('writer', self::reply($this->draftReply()));
        $this->fake->respond('layout-planner', self::reply(self::PLANS));
        [$session, $layouts, $conversation, $context] = $this->firstDraft();
        $layouts->choose($session, 'p1');

        $draft = Northfold::blocksDraft();
        $draft['page_builder'][1]['body'] .= "\n\n## Prices\n\nEach visit is priced by the size of the garden.";
        $this->fake->reset()->respond('writer', self::reply("<reply>Added prices.</reply>\n<draft>\n".Yaml::dump($draft, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK).'</draft>'));

        $response = $this->studio()->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $this->site());

        $this->assertSame(['writer'], array_map(fn ($request) => $request->agent, $this->fake->requests()));
        $this->assertSame(['x1.1'], array_keys($layouts->extras($session)->items()), 'extras are kept when the writer sends none');
        $p1 = $layouts->plans($session)->get('p1');
        $this->assertFalse($p1?->stale);
        $this->assertSame('p1', $layouts->chosen($session)?->id);
        $this->assertStringContainsString('## Prices', (string) json_encode($layouts->draftData($session, $this->site())));

        // A hand edit that leaves the layout unable to hold the text marks it stale; the writer's is used.
        $before = $session->draft;
        $edited = Northfold::blocksDraft();
        unset($edited['page_builder'][0]['heading']);
        $session->draft = Yaml::dump($edited, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $layouts->afterEdit($session, $before, $this->site());

        $this->assertTrue($layouts->plans($session)->get('p1')?->stale);
        $this->assertSame('w', $layouts->chosen($session)?->id);
        $this->fake->assertSent('writer');
        $this->assertCount(1, $this->fake->requests());

        // Refresh layouts: one planner call.
        $this->fake->reset()->respond('layout-planner', self::reply('<plans>[]</plans>'));
        $layouts->refresh($session, $this->site());
        $this->assertSame(['layout-planner'], array_map(fn ($request) => $request->agent, $this->fake->requests()));
        $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
    }

    public function test_deleting_an_extra_re_arranges_the_layouts_that_used_it(): void
    {
        $this->fake->respond('writer', self::reply($this->draftReply()));
        $this->fake->respond('layout-planner', self::reply(self::PLANS));
        [$session, $layouts] = $this->firstDraft();
        $this->fake->reset();

        $layouts->deleteExtra($session, 'x1.1', $this->site());

        $this->assertSame([], $layouts->extras($session)->items());
        $this->assertNotContains('stats', $layouts->plans($session)->get('p1')?->sequences()['page_builder'] ?? []);
        $this->assertFalse($layouts->plans($session)->get('p1')?->stale);
        $this->fake->assertNothingSent();
    }

    public function test_when_the_planner_fails_only_the_writers_layout_is_offered(): void
    {
        foreach ([self::reply('I would rather not.'), self::reply("<plans>\n- name: [unclosed\n</plans>"), new ProviderException('Overloaded', 'fake')] as $reply) {
            $this->setUp();
            $this->fake->respond('writer', self::reply($this->draftReply()));
            $reply instanceof ProviderException ? $this->fake->failWith('layout-planner', $reply) : $this->fake->respond('layout-planner', $reply);

            [$session, $layouts] = $this->firstDraft();

            $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
            $this->assertTrue($layouts->plans($session)->writer()?->suggested);
            $this->assertStringContainsString('layout planner', $this->logged());
        }
    }

    public function test_a_cut_off_reply_keeps_the_plans_that_are_whole(): void
    {
        $this->fake->respond('writer', self::reply($this->draftReply()));
        $cut = substr(self::PLANS, 0, (int) strpos(self::PLANS, 'description: A carousel'));
        $this->fake->respond('layout-planner', self::cutOff($cut), self::cutOff($cut));

        [$session, $layouts] = $this->firstDraft();

        $this->assertSame(['w', 'p1'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
        $this->assertCount(3, $this->fake->requests(), 'asked again once with more room, as every agent is');
    }

    public function test_nothing_to_arrange_asks_no_planner(): void
    {
        $session = Session::start(Format::Craft, 'note', []);
        $context = new WriterContext(new ContentKind('note', 'Note'), '', Layout::fromSchema(Northfold::plain()), '');
        $this->fake->respond('writer', self::reply("<reply>Done.</reply>\n<draft>\ntitle: A note\ndetails: Short.\n</draft>"));
        $layouts = new SessionLayouts($this->studio());
        $conversation = new Conversation([['role' => 'user', 'content' => 'A note.']]);

        $response = $this->studio()->write($conversation, $context);
        $session->answer($response->reply, $response->document);
        $usage = $layouts->afterWriter($session, null, $response, $conversation, $context, new LayoutContext(Northfold::plain()));

        $this->assertSame(0, $usage->input);
        $this->assertSame(['writer'], array_map(fn ($request) => $request->agent, $this->fake->requests()));
        $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
    }
}
