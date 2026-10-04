<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutDiff;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutGate;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Placement;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Transform;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use PHPUnit\Framework\TestCase;

/**
 * Only noticeably different layouts are offered, over plans the planner
 * really proposed on the test sites (tests/Fixtures/arrange/gate).
 */
final class LayoutGateTest extends TestCase
{
    public function test_a_quoted_closing_line_and_two_lists_as_paragraphs_are_not_offered(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-near-copies', Northfold::richText());

        $quoted = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);
        $prose = LayoutDiff::between($plans[0], $plans[2], $plans[0], $units, $extras, $schema);

        // One line at the foot of the page.
        $this->assertSame(1, $quoted->regions);
        $this->assertLessThan(0.02, $quoted->share());
        $this->assertSame(['quote'], array_column($quoted->added, 'kind'));
        $this->assertSame(['u8'], $quoted->changedUnits());

        // Two lists, in two places, an eighth of the page.
        $this->assertSame(2, $prose->regions);
        $this->assertEqualsWithDelta(0.12, $prose->share(), 0.02);
        $this->assertSame(['u6', 'u7'], $prose->changedUnits());

        $gate = (new LayoutGate)->filter($plans, $units, $extras, $schema, 'p2');

        $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, $gate['kept']));
        $this->assertEqualsCanonicalizing(['p1', 'p2'], array_keys($gate['dropped']));
        $this->assertStringContainsString('too like the writer\'s (12% of the page in 2 places, 0 block changes)', $gate['dropped']['p2']);
    }

    public function test_page_builder_alternatives_with_blocks_added_or_split_are_offered(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('pages-builder', self::craftPages());

        $split = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);
        $cta = LayoutDiff::between($plans[0], $plans[2], $plans[0], $units, $extras, $schema);

        $this->assertSame(2, $split->blockEdits);
        $this->assertSame(1, $cta->blockEdits);
        $this->assertSame(['set:cta', 'field'], array_column($cta->added, 'kind'));

        $gate = (new LayoutGate)->filter($plans, $units, $extras, $schema);

        $this->assertSame(['w', 'p1', 'p2'], array_map(fn (Plan $plan) => $plan->id, $gate['kept']));
        $this->assertSame([], $gate['dropped']);
    }

    public function test_lead_in_paragraphs_as_checklists_are_offered(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-checklists', self::craftJournal());

        $diff = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);

        $this->assertGreaterThan(LayoutGate::SHARE, $diff->share());
        $this->assertSame(['w', 'p2'], array_map(fn (Plan $plan) => $plan->id, (new LayoutGate)->filter($plans, $units, $extras, $schema)['kept']));
    }

    public function test_of_two_near_copies_of_each_other_the_preferred_one_stays(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-checklists', self::craftJournal());
        $twin = $plans[1]->with(id: 'p3');

        $first = (new LayoutGate)->filter([$plans[0], $plans[1], $twin], $units, $extras, $schema);
        $preferred = (new LayoutGate)->filter([$plans[0], $plans[1], $twin], $units, $extras, $schema, 'p3');

        $this->assertSame(['w', 'p2'], array_map(fn (Plan $plan) => $plan->id, $first['kept']));
        $this->assertSame(['w', 'p3'], array_map(fn (Plan $plan) => $plan->id, $preferred['kept']));
        $this->assertStringContainsString('too like p3', $preferred['dropped']['p2']);
    }

    public function test_the_writers_layout_and_a_plan_as_written_construct_by_construct_do_not_differ(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-near-copies', Northfold::richText());

        // "Quoted close" without its quote: u8 spelled out piece by piece.
        $spelled = $plans[1]->with(fields: ['body' => array_map(fn ($block) => $block->type === 'quote' ? new PlanBlock('p', [new Placement('@body', ['u8#8'])]) : $block, $plans[1]->fields['body'])]);

        $this->assertTrue(LayoutDiff::between($plans[0], $spelled, $plans[0], $units, $extras, $schema)->none());
    }

    public function test_each_layout_says_what_it_changes_and_where(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-near-copies', Northfold::richText());
        $quoted = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);
        $prose = LayoutDiff::between($plans[0], $plans[2], $plans[0], $units, $extras, $schema);

        $this->assertSame(['Closing line as a quote'], $quoted->summary());
        $this->assertSame([['field' => 'body', 'block' => null, 'section' => 5]], $quoted->places());
        $this->assertSame(['Lists as paragraphs'], $prose->summary());
        $this->assertSame([['field' => 'body', 'block' => null, 'section' => 3], ['field' => 'body', 'block' => null, 'section' => 4]], $prose->places());

        [$plans, $units, $extras, $schema] = self::fixture('pages-builder', self::craftPages());
        $split = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);
        $cta = LayoutDiff::between($plans[0], $plans[2], $plans[0], $units, $extras, $schema);

        $this->assertSame(['Text split into 3 blocks', 'A section moved down'], $split->summary());
        $this->assertSame([['field' => 'pageBuilder', 'block' => 2, 'section' => null], ['field' => 'pageBuilder', 'block' => 4, 'section' => null]], $split->places());
        $this->assertSame(['Call to action added'], $cta->summary());
        $this->assertSame([['field' => 'pageBuilder', 'block' => 1, 'section' => null]], $cta->places());

        [$plans, $units, $extras, $schema] = self::fixture('statamic-pages', self::statamicPages());
        $quote = LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema);

        $this->assertSame(['Quote moved up', 'Text blocks joined', 'A section moved up'], $quote->summary());
        $this->assertSame([['field' => 'page_builder', 'block' => 1, 'section' => null], ['field' => 'page_builder', 'block' => 2, 'section' => 1]], $quote->places());
        $this->assertTrue(LayoutGate::noticeable($quote));

        [$plans, $units, $extras, $schema] = self::fixture('journal-checklists', self::craftJournal());
        $this->assertSame(['Paragraphs as lists'], LayoutDiff::between($plans[0], $plans[1], $plans[0], $units, $extras, $schema)->summary());
        $this->assertSame([], LayoutDiff::between($plans[0], $plans[0], $plans[0], $units, $extras, $schema)->summary());
    }

    public function test_a_layout_that_only_moves_heading_levels_the_seo_pass_moves_back_is_not_different(): void
    {
        [$plans, $units, $extras, $schema] = self::fixture('journal-near-copies', Northfold::richText());
        $writer = $plans[0];
        $smaller = $writer->with(id: 'p9', fields: ['body' => array_map(fn (PlanBlock $block) => new PlanBlock($block->type, array_map(fn (Placement $placement) => new Placement($placement->field, $placement->from, Transform::HeadingLevel, ['level' => 3]), $block->placements)), $writer->fields['body'])]);

        $this->assertTrue(LayoutDiff::between($writer, $smaller, $writer, $units, $extras, $schema)->none(), 'Every heading one smaller is the same page once the pass fits them.');
        $this->assertSame(['w'], array_map(fn (Plan $plan) => $plan->id, (new LayoutGate)->filter([$writer, $smaller], $units, $extras, $schema)['kept']));
    }

    public function test_the_recorded_drafts_and_layouts_rebuild_the_same_but_for_headings(): void
    {
        $seo = new SeoPass;

        foreach (['journal-near-copies' => Northfold::richText(), 'journal-checklists' => self::craftJournal(), 'pages-builder' => self::craftPages(), 'statamic-pages' => self::statamicPages()] as $name => $schema) {
            [$plans, $units, $extras] = self::fixture($name, $schema);
            $raw = json_decode((string) file_get_contents(__DIR__."/../Fixtures/arrange/gate/{$name}.json"), true);
            $draft = Draft::parse($raw['draft']);

            foreach ($plans as $plan) {
                $arranged = (new Arranger)->arrange($plan, $units, $extras, $draft, $schema);
                [$fitted] = $seo->headings($arranged, $schema);

                $this->assertSame(self::without($arranged), self::without($fitted), "{$name} {$plan->id}: only headings change.");
                $this->assertSame($fitted, $seo->headings($fitted, $schema)[0], "{$name} {$plan->id}: fitting twice is fitting once.");
            }
        }
    }

    /**
     * Draft data's words, with heading marks and bold taken out.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function without(array $data): array
    {
        $words = [];

        array_walk_recursive($data, function (mixed $value) use (&$words): void {
            if (is_string($value)) {
                array_push($words, ...NormalisedText::words((string) preg_replace('/[*#]+/', ' ', $value)));
            }
        });

        return $words;
    }

    /**
     * The fixture's plans, still valid for its draft.
     *
     * @return array{0: list<Plan>, 1: Units, 2: Extras, 3: Schema}
     */
    private static function fixture(string $name, Schema $schema): array
    {
        $raw = json_decode((string) file_get_contents(__DIR__."/../Fixtures/arrange/gate/{$name}.json"), true);
        $draft = Draft::parse($raw['draft']);
        $units = Units::fromDraft($draft, $schema);
        $extras = Extras::fromArray($raw['extras']);
        $plans = Plans::fromArray($raw['plans'])->all();

        $kept = (new PlanValidator)->valid($plans, $units, $extras, $draft, $schema);
        self::assertSame(array_map(fn (Plan $plan) => $plan->id, $plans), array_map(fn (Plan $plan) => $plan->id, $kept), 'The fixture\'s plans are valid.');

        return [$plans, $units, $extras, $schema];
    }

    /** The Craft test site's Pages entry type. */
    private static function craftPages(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('pageBuilder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text, 'Heading'), new Field('subheading', Kind::Text, 'Subheading'), new Field('image', Kind::Reference, 'Image', files: true)]),
                'text' => new Set('Text', '', [new Field('text', Kind::RichText, 'Text')]),
                'quoteBlock' => new Set('Quote', '', [new Field('quote', Kind::LongText, 'Quote'), new Field('attribution', Kind::Text, 'Attribution')]),
                'cta' => new Set('Call to action', '', [new Field('heading', Kind::Text, 'Heading'), new Field('ctaText', Kind::Text, 'Text')]),
            ]),
        ]);
    }

    /** The Statamic test site's Pages blueprint. */
    private static function statamicPages(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text, 'Heading'), new Field('subheading', Kind::LongText, 'Subheading'), new Field('image', Kind::Reference, 'Image', files: true), new Field('button_text', Kind::Text, 'Button text')]),
                'text' => new Set('Text', '', [new Field('text', Kind::RichText, 'Text', type: 'bard')]),
                'image' => new Set('Image', '', [new Field('image', Kind::Reference, 'Image', files: true), new Field('caption', Kind::Text, 'Caption')]),
                'quote' => new Set('Quote', '', [new Field('quote', Kind::LongText, 'Quote'), new Field('attribution', Kind::Text, 'Attribution')]),
                'cta' => new Set('Call to action', '', [new Field('heading', Kind::Text, 'Heading'), new Field('text', Kind::LongText, 'Text'), new Field('link_text', Kind::Text, 'Link text')]),
            ]),
        ]);
    }

    /** The Craft test site's Journal entry type. */
    private static function craftJournal(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('excerpt', Kind::LongText, 'Excerpt'),
            new Field('body', Kind::RichText, 'Body'),
        ]);
    }
}
