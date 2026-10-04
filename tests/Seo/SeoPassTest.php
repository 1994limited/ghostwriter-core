<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Placement;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanOrigin;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Transform;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Violation;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Seo\H1Source;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\LayoutBrief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Studio\StudioTestCase;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;

/**
 * The SEO pass in the writing pipeline (seo-layer-design.md §5.1, rows 1
 * and 2): ① fits the writer's draft before units are cut, ② fits every
 * plan when it is built, neither calls a model, and both are idempotent.
 */
final class SeoPassTest extends StudioTestCase
{
    /** A Bard-like body whose editor has only H2 and H3, as on the Statamic test site. */
    private static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', type: 'bard', meta: [HeadingLevels::META => [2, 3]]),
        ]);
    }

    private const WRITER = <<<'MD'
        <reply>Here is a first draft.</reply>
        <draft>
        title: Winter garden care
        body: |
          # Winter garden care

          Winter is when a garden is set up for the year.

          ### The visits

          **November: Cut back.** Prune the shrubs that need it.

          #### What we bring

          Secateurs and a barrow.

          ### Who it suits

          Gardens with mixed borders.
        </draft>
        MD;

    public function test_the_first_draft_starts_at_h2_under_the_title_skips_nothing_and_never_goes_below_h3(): void
    {
        $this->fake->respond('writer', self::reply(self::WRITER, 900, 700));
        [$session, $layouts, $site] = $this->firstDraft();

        $body = (string) Draft::parse((string) $session->draft)->data['body'];

        $this->assertSame("## Winter garden care\n\nWinter is when a garden is set up for the year.\n\n### The visits\n\n**November: Cut back.** Prune the shrubs that need it.\n\n**What we bring.** Secateurs and a barrow.\n\n### Who it suits\n\nGardens with mixed borders.", $body);
        $this->assertSame(['writer'], array_map(fn ($request) => $request->agent, $this->fake->requests()), 'No model call for headings.');

        // Units are cut from the fixed text, and a second pass changes nothing.
        $units = $session->units;
        $draft = $session->draft;
        $layouts->afterEdit($session, $session->draft, $site);
        $this->assertSame($draft, $session->draft);
        $this->assertSame($units, $session->units);
        $this->assertSame(['## Winter garden care', '### The visits', '### Who it suits'], array_values(array_filter(explode("\n", $body), fn (string $line) => str_starts_with($line, '#'))));
    }

    public function test_an_edit_that_brings_back_a_heading_the_editor_cant_show_is_fitted_again(): void
    {
        $this->fake->respond('writer', self::reply(self::WRITER, 900, 700));
        [$session, $layouts, $site] = $this->firstDraft();

        $before = $session->draft;
        $session->draft = str_replace('### Who it suits', '#### Who it suits', (string) $session->draft);
        $layouts->afterEdit($session, $before, $site);

        $this->assertStringContainsString('**Who it suits.** Gardens with mixed borders.', (string) $session->draft);
        $this->assertSame([], $this->fake->requests() === [] ? [] : array_slice($this->fake->requests(), 1));
    }

    public function test_every_plan_is_fitted_when_it_is_built_and_nothing_is_stored(): void
    {
        $schema = Northfold::richText();
        $session = Session::start(Format::Statamic, 'journal', []);
        $session->draft = (new DraftEditor)->dump(Northfold::richTextDraft());
        $site = new LayoutContext($schema);
        $layouts = new SessionLayouts($this->studio(), new Layouts, $this->logger());
        $layouts->afterEdit($session, null, $site);

        $draft = Draft::parse((string) $session->draft);
        $units = Units::fromDraft($draft, $schema)->restore($session->units);
        // "The visits" section, its lead-ins as h4s: deeper than the h2 above them by two.
        $body = $units->at(FieldPath::of('body'));
        $plan = new Plan('p1', PlanOrigin::Model, 'Headed', 'Lead-ins as headings', ['body' => [
            new PlanBlock('text', [new Placement(Placement::BODY, [$body[0]->id])]),
            new PlanBlock('text', [new Placement(Placement::BODY, [$body[1]->id], Transform::LeadInToHeading, ['level' => 4])]),
            ...array_map(fn ($unit) => new PlanBlock('text', [new Placement(Placement::BODY, [$unit->id])]), array_slice($body, 2)),
        ]]);
        $session->plans = (new Plans([Plans::fromDraft($draft, $units, $schema), $plan]))->toArray();
        $stored = $session->plans;

        $data = $layouts->draftData($session, $site, 'p1');
        $this->assertStringContainsString("## The visits\n\n### November: Cut back\n\nPrune", (string) $data['body'], 'The plan\'s h4s are one below their section.');
        $this->assertStringNotContainsString('####', (string) $data['body']);

        $narrow = new LayoutContext(new Schema([$schema->fields[0], $schema->fields[1], $schema->fields[2]->with(meta: [HeadingLevels::META => [2]])]));
        $this->assertStringContainsString("## The visits\n\n**November: Cut back.** Prune", (string) $layouts->draftData($session, $narrow, 'p1')['body'], 'With only H2, they stay lead-ins.');
        $this->assertSame($stored, $session->plans, 'Nothing is stored.');
    }

    public function test_a_plan_that_makes_headings_where_the_editor_shows_none_is_dropped(): void
    {
        $schema = new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', meta: [HeadingLevels::META => []]),
        ]);
        $draft = Draft::parse("title: Winter care\nbody: |\n  **November: Cut back.** Prune.\n\n  **January: Feed.** Mulch.\n\n  Done.");
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);
        $id = $units->at(FieldPath::of('body'))[0]->id;
        $headed = new Plan('p1', PlanOrigin::Model, 'Headed', '', ['body' => [new PlanBlock('text', [new Placement(Placement::BODY, [$id], Transform::LeadInToHeading, ['level' => 3])])]]);
        $level = new Plan('p2', PlanOrigin::Model, 'Level', '', ['body' => [new PlanBlock('h2', [new Placement(Placement::BODY, ["{$id}#1:lead"])]), new PlanBlock('p', [new Placement(Placement::BODY, ["{$id}#1:rest", "{$id}#2", "{$id}#3"])])]]);

        $validated = (new PlanValidator)->validate([$writer, $headed, $level], $units, new Extras([]), $draft, $schema);

        $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, $validated->kept));
        $this->assertContains(Violation::NO_HEADINGS, $validated->rules()['p1']);
        $this->assertContains(Violation::NO_HEADINGS, $validated->rules()['p2']);

        // A level the field can't show is no violation: the pass maps it.
        $deep = new Schema([$schema->fields[0], $schema->fields[1]->with(meta: [HeadingLevels::META => [2]])]);
        $this->assertSame([], (new PlanValidator)->check($headed, $units, new Extras([]), $draft, $deep));
    }

    public function test_the_writer_and_the_planner_are_told_the_real_levels(): void
    {
        $schema = new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('body', Kind::RichText, 'Body', meta: [HeadingLevels::META => [2, 3]]),
            new Field('notes', Kind::RichText, 'Notes', meta: [HeadingLevels::META => []]),
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: [
                'section' => new Set('Section', '', [new Field('heading', Kind::Text), new Field('text', Kind::RichText)]),
            ]),
        ]);
        $sections = new RenderProfile('pages', fieldLevels: ['section.heading' => 2]);

        $described = (new SchemaDescriber)->describe($schema, null, $sections);
        $this->assertStringContainsString("- `body` (markdown). Section headings: `##` and `###` (the page's main heading is the title)", $described);
        $this->assertStringContainsString('- `notes` (markdown). No headings: use a bold lead-in instead', $described);
        $this->assertStringContainsString('- `text` (markdown). Section headings: `###` to `######`', $described, 'One below the section\'s own heading.');

        $hero = new RenderProfile('pages', H1Source::Field, 'hero.heading');
        $this->assertStringContainsString("Section headings: `##` and `###` (the page's main heading is `hero.heading`)", Layout::fromSchema($schema, [], new SchemaDescriber, $hero)->fields);
        $this->assertStringContainsString("Section headings: `#` and `##` (`#` is the page's main heading)", (new SchemaDescriber)->describe(new Schema([new Field('body', Kind::RichText, meta: [HeadingLevels::META => [1, 2]])]), null, new RenderProfile('k', H1Source::None, null, [], ['body'])));

        $draft = Draft::parse("title: Winter care\nbody: |\n  Intro.\n\n  ## Visits\n\n  Four.\n\n  ## Who\n\n  Borders.\nnotes: |\n  Some notes.");
        $units = Units::fromDraft($draft, $schema);
        $brief = new LayoutBrief($units, new Extras([]), $schema, [], [], Plans::fromDraft($draft, $units, $schema), 2, null, $sections);
        $this->assertStringContainsString('body: rich text. Constructs: text, p, h2, h3, list, quote.', $brief->prompt());
        $this->assertStringContainsString('notes: rich text. Constructs: text, p, list, quote. No headings.', $brief->prompt());
        $this->assertStringContainsString('- section: heading (text, one line), text (rich text, headings h3–h6)', $brief->prompt());
    }

    public function test_heading_levels_come_from_the_field_spec(): void
    {
        $this->assertSame([2, 3], HeadingLevels::allowed(Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings' => ['h3', 2, '2', 9]])));
        $this->assertSame([], HeadingLevels::allowed(Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings' => []])));
        $this->assertSame(HeadingLevels::ALL, HeadingLevels::allowed(Field::fromSpec(['handle' => 'body', 'kind' => 'richtext'])));
        $this->assertSame(HeadingLevels::ALL, HeadingLevels::allowed(Field::fromSpec(['handle' => 'body', 'kind' => 'longtext', 'type' => 'markdown'])));
        $this->assertSame([], HeadingLevels::allowed(Field::fromSpec(['handle' => 'intro', 'kind' => 'longtext'])));
        $this->assertSame([2, 3], HeadingLevels::allowed(Field::fromArray(Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings' => [2, 3]])->toArray())), 'The levels survive the fixtures\' array form.');
    }

    public function test_arranged_data_is_fitted_inside_blocks_too(): void
    {
        $schema = Northfold::blocks();
        $data = ['title' => 'Winter care', 'page_builder' => [
            ['type' => 'text', 'body' => "# Big\n\nText.\n\n#### Deep\n\nMore."],
            ['type' => 'section', 'heading' => 'Our visits', 'children' => [['type' => 'text', 'body' => "## Inner\n\nText."]]],
        ]];
        $profile = new RenderProfile('pages', fieldLevels: ['section.heading' => 2]);

        [$fitted, $changes] = (new SeoPass)->headings($data, $schema, $profile);

        $this->assertSame("## Big\n\nText.\n\n### Deep\n\nMore.", $fitted['page_builder'][0]['body']);
        $this->assertSame("## Inner\n\nText.", $fitted['page_builder'][1]['children'][0]['body'], 'A text block inside a section starts at the page\'s top: its own set has no heading.');
        $this->assertSame(['page_builder.0.body'], array_keys($changes));
    }

    /**
     * @return array{0: Session, 1: SessionLayouts, 2: LayoutContext}
     */
    private function firstDraft(): array
    {
        $schema = self::schema();
        $session = Session::start(Format::Statamic, 'journal', ['brief' => 'Winter care.']);
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter care.']]);
        $context = new WriterContext(new ContentKind('journal', 'Journal'), '', Layout::fromSchema($schema), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());
        $site = new LayoutContext($schema);

        $response = $studio->write($conversation, $context);
        $before = $session->draft;
        $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);

        return [$session, $layouts, $site];
    }
}
