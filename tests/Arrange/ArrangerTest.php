<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Content;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Transform;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use PHPUnit\Framework\TestCase;

/**
 * The arranger across the three schema shapes: a page builder (with
 * nested blocks), one rich-text field, and plain fields. A plan only moves
 * and reshapes words; the validator confirms each plan here is usable.
 */
final class ArrangerTest extends TestCase
{
    public static function plan(string $yaml, Schema $schema): Plan
    {
        $plans = (new PlanReader)->read($yaml, $schema);
        self::assertCount(1, $plans);

        return $plans[0];
    }

    public function test_the_units_of_the_page(): void
    {
        $units = Units::fromDraft(Northfold::blocksDraft(), Northfold::blocks());

        $this->assertSame(
            ['u1 title', 'u2 summary', 'u3 page_builder/0/heading', 'u4 page_builder/0/subheading', 'u5 page_builder/0/image', 'u6 page_builder/1/body~0', 'u7 page_builder/1/body~1', 'u8 page_builder/1/body~2', 'u9 page_builder/3/heading', 'u10 page_builder/3/button'],
            array_map(fn (Unit $unit) => $unit->id.' '.$unit->where(), $units->all()),
        );
    }

    public function test_a_page_builder_layout_turns_sections_into_cards_and_uses_extras(): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema);
        $plan = self::plan(<<<'YAML'
            - name: Scannable
              description: The visits as cards, then who it suits
              follows: p-2
              page_builder:
                - type: hero
                  place: { heading: u3, subheading: u4, image: u5 }
                - type: stats
                  place: { items: [x1.1, x1.2] }
                - type: text
                  place: { body: u6 }
                - type: section
                  place: { heading: "u7#1" }
                  children:
                    - type: card
                      place: { heading: "u7#2:lead", body: "u7#2:rest" }
                    - type: card
                      place: { heading: "u7#3:lead", body: "u7#3:rest" }
                - type: text
                  place: { body: ["u8#1", "u8#2"] }
                - type: ticks
                  place: { items: ["u8#3", "u8#4"] }
                - type: quote
                  place: { text: "u8#5" }
                  transform: as-quote
                - type: faq
                  place: { questions: [x2.1] }
                - type: cta
                  place: { heading: u9, button: u10 }
            YAML, $schema);

        $this->assertSame(['x1.1', 'x1.2', 'x2.1'], $plan->extrasUsed());
        $this->assertSame([], (new PlanValidator)->check($plan, $units, Northfold::extras(), $draft, $schema));

        $arranged = (new Arranger)->arrange($plan, $units, Northfold::extras(), $draft, $schema);
        $builder = $arranged['page_builder'];

        $this->assertSame(['Winter garden care', 'Four visits between November and February.'], [$arranged['title'], $arranged['summary']], 'fields it does not arrange are as they were');
        $this->assertSame(['type' => 'hero', 'heading' => 'Winter garden care', 'subheading' => 'Set the garden up for spring.', 'image' => 'assets::garden.jpg'], $builder[0]);
        $this->assertSame(['type' => 'stats', 'items' => [['value' => '4', 'label' => 'visits a winter'], ['label' => 'From [[ask: price per visit]] a visit']]], $builder[1]);
        $this->assertSame(['type' => 'text', 'body' => 'Winter is when a garden is set up for the year.'], $builder[2]);
        $this->assertSame(['type' => 'section', 'heading' => 'The visits', 'children' => [
            ['type' => 'card', 'heading' => 'November: Cut back', 'body' => 'Prune the shrubs that need it.'],
            ['type' => 'card', 'heading' => 'January: Feed', 'body' => 'Mulch the beds and [[ask: what else in January]].'],
        ]], $builder[3]);
        $this->assertSame("## Who it suits\n\nGardens with mixed borders. [Talk to us](#gw-link:contact-page)", $builder[4]['body']);
        $this->assertSame(['Lawns', 'Gravel'], $builder[5]['items']);
        $this->assertSame('We used to clear everything in October.', $builder[6]['text']);
        $this->assertSame([['question' => 'How often do you visit?', 'answer' => 'Four times, between November and February.']], $builder[7]['questions']);
        $this->assertSame(['type' => 'cta', 'heading' => 'Book a winter visit', 'button' => 'Book now'], $builder[8]);

        $built = (new EntryBuilder)->build($arranged, $schema);
        $this->assertSame([], $built->notes);
        $this->assertCount(9, $built->data['page_builder']);
    }

    public function test_the_writers_plan_keeps_each_blocks_other_values(): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);

        $this->assertSame(['hero', 'text', 'spacer', 'cta'], $writer->sequences()['page_builder']);
        $this->assertSame([0, 1, 2, 3], array_map(fn ($block) => $block->origin, $writer->fields['page_builder']));
        $this->assertSame([['heading', ['u3']], ['subheading', ['u4']], ['image', ['u5']]], array_map(fn ($placement) => [$placement->field, $placement->from], $writer->fields['page_builder'][0]->placements));

        // The writer's plan with the cta and hero swapped: each keeps its own values.
        $blocks = $writer->fields['page_builder'];
        $swapped = $writer->with(['page_builder' => [$blocks[3], $blocks[1], $blocks[2], $blocks[0]]], id: 'p1');
        $arranged = (new Arranger)->arrange($swapped, $units, [], $draft, $schema);

        $this->assertSame(['cta', 'text', 'spacer', 'hero'], array_column($arranged['page_builder'], 'type'));
        $this->assertSame($draft['page_builder'][0], $arranged['page_builder'][3], 'the hero keeps its image and background');
        $this->assertSame(['type' => 'spacer', 'size' => 20], $arranged['page_builder'][2]);
    }

    public function test_a_rich_text_field_in_three_structures(): void
    {
        $schema = Northfold::richText();
        $draft = Northfold::richTextDraft();
        $units = Units::fromDraft($draft, $schema);
        $arranger = new Arranger;
        $validator = new PlanValidator(new EntryBuilder(richText: new BardDialect));

        $writer = Plans::fromDraft($draft, $units, $schema);
        $this->assertSame(['text', 'text', 'text'], $writer->sequences()['body']);
        $this->assertSame($draft, $arranger->arrange($writer, $units, [], $draft, $schema));

        $flowing = self::plan(<<<'YAML'
            - name: Flowing essay
              description: Bold lead-ins, the quote as a break
              body:
                - { type: quote, from: "u4#5" }
                - { type: text, from: u2 }
                - { type: text, from: u3, transform: heading-to-lead-in }
                - { type: text, from: ["u4#1", "u4#2"], transform: heading-to-lead-in }
                - { type: p, from: ["u4#3", "u4#4"] }
            YAML, $schema);

        $this->assertSame([], $validator->check($flowing, $units, [], $draft, $schema));
        $this->assertSame(
            "> We used to clear everything in October.\n\nWinter is when a garden is set up for the year.\n\n**The visits.** **November: Cut back.** Prune the shrubs that need it.\n\n**January: Feed.** Mulch the beds and [[ask: what else in January]].\n\n**Who it suits.** Gardens with mixed borders. [Talk to us](#gw-link:contact-page)\n\nLawns\n\nGravel",
            $arranger->arrange($flowing, $units, [], $draft, $schema)['body'],
        );

        $scannable = self::plan(<<<'YAML'
            - name: Scannable
              description: At a glance first, a heading per visit
              excerpt: x3.1
              body:
                - { type: list, from: [x1.1] }
                - { type: text, from: u2 }
                - { type: text, from: u3, transform: lead-in-to-heading, level: 3 }
                - { type: h2, from: "u4#1" }
                - { type: list, from: ["u4#2", "u4#3", "u4#4"] }
                - { type: "set:pull_quote", from: "u4#5" }
                - { type: text, from: x2.1 }
            YAML, $schema);

        $this->assertSame([], $validator->check($scannable, $units, Northfold::extras(), $draft, $schema));
        $arranged = $arranger->arrange($scannable, $units, Northfold::extras(), $draft, $schema);
        $this->assertSame('Four visits that set a garden up for spring.', $arranged['excerpt'], 'the intro extra fills the empty excerpt');
        $this->assertSame(
            "- 4 visits a winter\n\nWinter is when a garden is set up for the year.\n\n## The visits\n\n### November: Cut back\n\nPrune the shrubs that need it.\n\n### January: Feed\n\nMulch the beds and [[ask: what else in January]].\n\n## Who it suits\n\n- Gardens with mixed borders. [Talk to us](#gw-link:contact-page)\n- Lawns\n- Gravel\n\n> We used to clear everything in October.\n\n### How often do you visit?\n\nFour times, between November and February.",
            $arranged['body'],
        );

        $bard = (new BardDialect)->fromMarkdown($arranged['body'], $schema->fields[2]);
        $this->assertIsArray($bard);
        $this->assertContains('set', array_column($bard, 'type'), 'the dialect stores the block quote as the pull quote set');
    }

    public function test_a_heading_construct_of_lead_ins_is_a_heading_per_lead_in(): void
    {
        $schema = Northfold::richText();
        $draft = Northfold::richTextDraft();
        $units = Units::fromDraft($draft, $schema);

        // The shape a planner sent for real: an h3 construct over lead-in
        // paragraphs, turned to headings, each keeping its paragraph.
        $headed = self::plan(<<<'YAML'
            - name: Headed visits
              description: A heading per visit
              body:
                - { type: text, from: u2 }
                - { type: h2, from: "u3#1" }
                - { type: h3, from: ["u3#2", "u3#3"], transform: lead-in-to-heading, level: 3 }
                - { type: text, from: u4 }
            YAML, $schema);

        $this->assertSame([], (new PlanValidator(new EntryBuilder(richText: new BardDialect)))->check($headed, $units, [], $draft, $schema));
        $this->assertSame(
            "Winter is when a garden is set up for the year.\n\n## The visits\n\n### November: Cut back\n\nPrune the shrubs that need it.\n\n### January: Feed\n\nMulch the beds and [[ask: what else in January]].\n\n## Who it suits\n\nGardens with mixed borders. [Talk to us](#gw-link:contact-page)\n\n- Lawns\n- Gravel\n\n> We used to clear everything in October.",
            (new Arranger)->arrange($headed, $units, [], $draft, $schema)['body'],
        );

        $leadIns = Content::transform([
            new Piece(Piece::PARAGRAPH, '**Water deeply, and less often.** A good soak once a week.'),
            new Piece(Piece::PARAGRAPH, '**Then go, and stop worrying.**'),
        ], Transform::LeadInToHeading, ['level' => 2]);
        $this->assertSame("#### Water deeply, and less often\n\nA good soak once a week.\n\n#### Then go, and stop worrying", Arranger::construct('h4', $leadIns), 'each heading takes the construct\'s level');
        $this->assertSame('### Why winter matters', Arranger::construct('h3', [new Piece(Piece::PARAGRAPH, 'Why winter matters')]), 'a short paragraph made a heading is still one heading');
        $this->assertSame('## The visits In detail', Arranger::construct('h2', [new Piece(Piece::HEADING, '## The visits', 2), new Piece(Piece::HEADING, '### In detail', 3)]));
    }

    public function test_plain_fields_take_an_extra_in_an_empty_field_and_list_paragraphs(): void
    {
        $schema = Northfold::plain();
        $draft = Northfold::plainDraft();
        $units = Units::fromDraft($draft, $schema);

        $writer = Plans::fromDraft($draft, $units, $schema);
        $this->assertSame([], $writer->fields, 'plain fields are copied, not arranged');
        $this->assertSame($draft, (new Arranger)->arrange($writer, $units, [], $draft, $schema));

        $outside = self::plan("- name: Tags\n  tags: u2\n", $schema);
        $this->assertSame(['outside', 'missing', 'words'], array_values(array_unique(array_map(fn ($violation) => $violation->rule, (new PlanValidator)->check($outside, $units, [], $draft, $schema)))), 'the details would be there twice, and the tags gone');

        $plan = self::plan("- name: Short intro\n  intro: x3.1\n", $schema);
        $this->assertSame([], (new PlanValidator)->check($plan, $units, Northfold::extras(), $draft, $schema));
        $arranged = (new Arranger)->arrange($plan, $units, Northfold::extras(), $draft, $schema);
        $this->assertSame(['title' => 'Winter garden care', 'details' => $draft['details'], 'tags' => ['winter', 'care'], 'intro' => 'Four visits that set a garden up for spring.'], $arranged);
    }

    public function test_each_transform_reshapes_without_new_words(): void
    {
        $lead = [new Piece(Piece::PARAGRAPH, '**November: Cut back.** Prune it.'), new Piece(Piece::PARAGRAPH, 'No lead-in here.')];
        $section = [new Piece(Piece::HEADING, '## The visits', 2), new Piece(Piece::PARAGRAPH, 'Four of them.'), new Piece(Piece::HEADING, '### In detail', 3)];
        $items = [new Piece(Piece::ITEM, '- Lawns'), new Piece(Piece::ITEM, '- Gravel')];

        $cases = [
            [Transform::AsIs, $lead, [], "**November: Cut back.** Prune it.\n\nNo lead-in here."],
            [Transform::Split, $items, [], "- Lawns\n- Gravel"],
            [Transform::Join, $items, [], "- Lawns\n- Gravel"],
            [Transform::LeadInToHeading, $lead, ['level' => 4], "#### November: Cut back\n\nPrune it.\n\nNo lead-in here."],
            [Transform::HeadingToLeadIn, $section, [], "**The visits.** Four of them.\n\n### In detail"],
            [Transform::ParagraphsToList, $lead, [], "- **November: Cut back.** Prune it.\n- No lead-in here."],
            [Transform::ListToParagraphs, $items, [], "Lawns\n\nGravel"],
            [Transform::HeadingLevel, $section, ['level' => 3], "### The visits\n\nFour of them.\n\n#### In detail"],
            [Transform::AsQuote, $items, [], "> Lawns\n>\n> Gravel"],
        ];

        foreach ($cases as [$transform, $pieces, $options, $expected]) {
            $this->assertSame($expected, Content::joinMarkdown(Content::transform($pieces, $transform, $options)), $transform->value);
        }

        $this->assertSame(['November: Cut back', 'Prune it.'], Content::leadIn($lead[0]));
        $this->assertNull(Content::leadIn($lead[1]));
    }

    public function test_html_rich_text_in_a_block_is_markdown_in_the_arranged_draft(): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema, new HtmlDialect);
        $plan = self::plan("- name: Short\n  page_builder:\n    - type: text\n      place: { body: [u6, u7, u8] }\n      transform: heading-level\n      level: 3\n", $schema);

        $body = (new Arranger)->arrange($plan, $units, [], $draft, $schema)['page_builder'][0]['body'];
        $this->assertStringContainsString("### The visits\n\n**November: Cut back.**", $body);
    }
}
