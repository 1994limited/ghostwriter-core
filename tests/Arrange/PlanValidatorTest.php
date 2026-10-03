<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Violation;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** One failing plan per rule of §6.2.1, each over the services page. */
final class PlanValidatorTest extends TestCase
{
    /** The page laid out validly: everything below changes one thing. */
    public const VALID = <<<'YAML'
        - name: Valid
          page_builder:
            - type: hero
              place: { heading: u3, subheading: u4, image: u5 }
            - type: text
              place: { body: [u6, u7, u8] }
            - type: cta
              place: { heading: u9, button: u10 }
        YAML;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        $hero = "    - type: hero\n      place: { heading: u3, subheading: u4, image: u5 }\n";
        $text = "    - type: text\n      place: { body: [u6, u7, u8] }\n";
        $cta = "    - type: cta\n      place: { heading: u9, button: u10 }\n";
        $plan = fn (string $blocks) => "- name: Broken\n  page_builder:\n".$blocks;

        yield 'a block type the field has no set for' => [Violation::UNKNOWN_BLOCK, $plan($hero.$text.$cta."    - type: carousel\n")];
        yield 'a child the nested builder has no set for' => [Violation::UNKNOWN_BLOCK, $plan($hero."    - type: section\n      place: { heading: \"u7#1\" }\n      children: [{ type: hero, place: { heading: u6 } }]\n    - type: text\n      place: { body: [\"u7#2\", \"u7#3\", u8] }\n".$cta)];
        yield 'a field the set does not have' => [Violation::UNKNOWN_FIELD, $plan($hero."    - type: text\n      place: { content: [u6, u7, u8] }\n".$cta)];
        yield 'a ref to nothing' => [Violation::UNKNOWN_REF, $plan($hero."    - type: text\n      place: { body: [u6, u7, u8, u99] }\n".$cta)];
        yield 'an extra that does not exist' => [Violation::UNKNOWN_REF, $plan($hero.$text.$cta."    - type: quote\n      place: { text: x9.1 }\n")];
        yield 'two paragraphs in a heading' => [Violation::KIND, $plan("    - type: hero\n      place: { heading: [u3, u4], image: u5 }\n".$text.$cta)];
        yield 'a section with headings in plain long text' => [Violation::KIND, $plan($hero."    - type: quote\n      place: { text: [u6, u7, u8] }\n".$cta)];
        yield 'an image in a text field' => [Violation::KIND, $plan("    - type: hero\n      place: { heading: u3, subheading: [u4, u5] }\n".$text.$cta)];
        yield 'text in an image field' => [Violation::KIND, $plan("    - type: hero\n      place: { heading: u3, image: [u4, u5] }\n".$text.$cta)];
        yield 'more blocks than the field allows' => [Violation::LIMITS, $plan($hero.$text.$cta.str_repeat("    - type: spacer\n", 10))];
        yield 'a required heading left empty' => [Violation::REQUIRED, $plan("    - type: hero\n      place: { subheading: [u3, u4], image: u5 }\n".$text.$cta)];
        yield 'a block for words with none' => [Violation::EMPTY_BLOCK, $plan($hero.$text.$cta."    - type: ticks\n")];
        yield 'a unit placed twice' => [Violation::DUPLICATED, $plan($hero.$text.$cta."    - type: ticks\n      place: { items: \"u8#3\" }\n")];
        yield 'a piece placed twice' => [Violation::DUPLICATED, $plan($hero."    - type: text\n      place: { body: [u6, u7, \"u8#1\", \"u8#2\", \"u8#3\", \"u8#4\", \"u8#5\", \"u8#5\"] }\n".$cta)];
        yield 'a lead-in placed with its whole paragraph' => [Violation::DUPLICATED, $plan($hero.$text.$cta."    - type: cta\n      place: { heading: \"u7#2:lead\" }\n")];
        yield 'an extra placed twice' => [Violation::DUPLICATED, $plan($hero.$text.$cta."    - type: stats\n      place: { items: [x1.1, x1.1] }\n")];
        yield 'a unit left out' => [Violation::MISSING, $plan($hero."    - type: text\n      place: { body: [u6, u7] }\n".$cta)];
        yield 'a piece left out' => [Violation::MISSING, $plan($hero."    - type: text\n      place: { body: [u6, u7, \"u8#1\", \"u8#2\", \"u8#3\", \"u8#4\"] }\n".$cta)];
        yield 'the lead of a lead-in placed without the rest' => [Violation::MISSING, $plan($hero."    - type: text\n      place: { body: [u6, \"u7#1\", \"u7#2:lead\", \"u7#3\", u8] }\n".$cta)];
        yield 'a unit of a field it does not arrange' => [Violation::OUTSIDE, $plan($hero.$text.$cta."    - type: quote\n      place: { text: u2 }\n")];
        yield 'an extra with no source and no ask' => [Violation::UNSOURCED_EXTRA, $plan($hero.$text.$cta."    - type: quote\n      place: { text: x4.1 }\n")];
        yield 'words in a block the site copies whole' => [Violation::BOILERPLATE, $plan($hero.$text."    - type: cta\n      place: { heading: u9, button: u10 }\n")];
        yield 'the same layout as the writer' => [Violation::SAME, "- name: Same\n  page_builder:\n    - type: hero\n      place: { heading: u3, subheading: u4, image: u5 }\n    - type: text\n      place: { body: [u6, u7, u8] }\n    - type: spacer\n    - type: cta\n      place: { heading: u9, button: u10 }\n"];
        yield 'a field that does not exist' => [Violation::UNKNOWN_FIELD, "- name: Broken\n  page_builder:\n".$hero.$text.$cta."  sidebar: u2\n"];
    }

    #[DataProvider('failures')]
    public function test_a_plan_breaking_one_rule_is_dropped(string $rule, string $yaml): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);
        $pattern = $rule === Violation::BOILERPLATE ? Pattern::fromArray(['blocks' => ['page_builder' => ['boilerplate' => ['cta']]]]) : null;

        // "sidebar" isn't a field, so the reader leaves it out; the plan is then built by hand.
        $plan = $rule === Violation::UNKNOWN_FIELD && str_contains($yaml, 'sidebar')
            ? (new Plan('p1', ArrangerTest::plan($yaml, $schema)->origin, 'Broken', '', ArrangerTest::plan($yaml, $schema)->fields + ['sidebar' => []]))
            : ArrangerTest::plan($yaml, $schema);

        $valid = ArrangerTest::plan(self::VALID, $schema);
        $this->assertSame([], (new PlanValidator)->check($valid, $units, Northfold::extras(), $draft, $schema, $pattern === null ? null : Pattern::fromArray(['blocks' => ['page_builder' => ['boilerplate' => ['spacer']]]])), 'the valid plan is valid');

        $rules = array_map(fn (Violation $violation) => $violation->rule, (new PlanValidator)->check($plan, $units, Northfold::extras(), $draft, $schema, $pattern, [$writer]));

        $this->assertContains($rule, $rules, implode(', ', $rules));
        $this->assertSame([$writer], (new PlanValidator)->valid([$writer, $plan], $units, Northfold::extras(), $draft, $schema, $pattern));
    }

    public function test_a_marker_that_changed_is_caught_even_when_the_words_did_not(): void
    {
        $schema = Northfold::richText();
        $draft = ['title' => 'A', 'body' => 'See [the page](#gw-link:contact-page).'];
        // A unit whose piece points the link elsewhere: a link's target is not one of its words.
        $units = Units::of([
            new Unit('u1', UnitKind::Text, FieldPath::of('title'), 'A', [new Piece(Piece::PARAGRAPH, 'A')]),
            new Unit('u2', UnitKind::Prose, FieldPath::of('body'), 'See [the page](#gw-link:contact-page).', [new Piece(Piece::PARAGRAPH, 'See [the page](#gw-link:about-page).')], null, [], 0),
        ]);
        $plan = ArrangerTest::plan("- name: Broken\n  body:\n    - { type: p, from: u2 }\n", $schema);

        $this->assertSame([Violation::MARKERS], array_map(fn (Violation $violation) => $violation->rule, (new PlanValidator)->check($plan, $units, [], $draft, $schema)));
    }

    public function test_the_round_trip_catches_an_option_that_does_not_exist(): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema);
        $plan = ArrangerTest::plan(self::VALID, $schema);
        $fields = $plan->fields;
        $fields['page_builder'][0] = new PlanBlock('hero', $fields['page_builder'][0]->placements, [], ['background' => 'purple']);

        $rules = array_map(fn (Violation $violation) => $violation->rule, (new PlanValidator)->check($plan->with($fields), $units, [], $draft, $schema));

        $this->assertSame([Violation::ROUND_TRIP], $rules);
    }
}
