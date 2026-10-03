<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanRepair;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitMatcher;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use PHPUnit\Framework\TestCase;

/** §6.2.2: after the text changes, layouts follow it with no model call, or are marked stale. */
final class PlanRepairTest extends TestCase
{
    private const SCANNABLE = <<<'YAML'
        - name: Scannable
          page_builder:
            - type: hero
              place: { heading: u3, subheading: u4, image: u5 }
            - type: text
              place: { body: u6 }
            - type: section
              place: { heading: "u7#1" }
              children:
                - { type: card, place: { heading: "u7#2:lead", body: "u7#2:rest" } }
                - { type: card, place: { heading: "u7#3:lead", body: "u7#3:rest" } }
            - type: text
              place: { body: ["u8#1", "u8#2", "u8#3", "u8#4"] }
            - type: quote
              place: { text: "u8#5" }
              transform: as-quote
            - type: stats
              place: { items: [x1.1] }
            - type: cta
              place: { heading: u9, button: u10 }
        YAML;

    /**
     * @param  array<string, mixed>  $draft
     * @return array{0: Plan, 1: Units, 2: list<string>}
     */
    private function edit(array $draft, ?Extras $extras = null): array
    {
        $schema = Northfold::blocks();
        $before = Units::fromDraft(Northfold::blocksDraft(), $schema);
        $after = (new UnitMatcher)->carry($before, Units::fromDraft($draft, $schema));
        $extras ??= Northfold::extras();
        $plan = (new PlanRepair)->repair(ArrangerTest::plan(self::SCANNABLE, $schema), $after, $extras, Plans::fromDraft($draft, $after, $schema));
        $violations = array_map('strval', (new PlanValidator)->check($plan, $after, $extras, $draft, $schema));

        return [$plan, $after, $violations];
    }

    public function test_a_removed_section_is_taken_out_of_the_layout(): void
    {
        $draft = Northfold::blocksDraft();
        $draft['page_builder'][1]['body'] = substr(Northfold::BODY, 0, (int) strpos(Northfold::BODY, "\n\n## Who it suits"));

        [$plan, , $violations] = $this->edit($draft);

        $this->assertSame([], $violations);
        $this->assertSame(['hero', 'text', 'section', 'stats', 'cta'], $plan->sequences()['page_builder'], 'its text block and quote went with it');
    }

    public function test_a_new_section_goes_after_the_one_before_it_in_a_block_of_the_writers_type(): void
    {
        $draft = Northfold::blocksDraft();
        $draft['page_builder'][1]['body'] = Northfold::BODY."\n\n## Prices\n\nEach visit is priced by the size of the garden.";

        [$plan, $units, $violations] = $this->edit($draft);

        $this->assertSame([], $violations);
        $this->assertSame('u11', $units->all()[8]->id);
        $this->assertSame(['hero', 'text', 'section', 'text', 'quote', 'text', 'stats', 'cta'], $plan->sequences()['page_builder']);
        $this->assertSame(['u11'], $plan->fields['page_builder'][5]->placements[0]->from);
        $this->assertStringStartsWith('## Prices', (new Arranger)->arrange($plan, $units, Northfold::extras(), $draft, Northfold::blocks())['page_builder'][5]['body']);
    }

    public function test_an_extra_the_editor_deleted_takes_its_block_with_it(): void
    {
        [$plan, , $violations] = $this->edit(Northfold::blocksDraft(), Northfold::extras()->without('x1.1'));

        $this->assertSame([], $violations);
        $this->assertNotContains('stats', $plan->sequences()['page_builder']);
    }

    public function test_a_lead_in_that_is_gone_empties_its_card_and_the_layout_needs_refreshing(): void
    {
        $draft = Northfold::blocksDraft();
        $draft['page_builder'][1]['body'] = str_replace('**January: Feed.** ', '', Northfold::BODY);

        [$plan, , $violations] = $this->edit($draft);

        $this->assertCount(1, $plan->fields['page_builder'][2]->children['children'], 'the card that had it is gone');
        $this->assertSame(['missing: u7#3 is not placed.', 'words: the arranged words are not the draft\'s.', 'markers: an [[ask: …]], a [[check: …]] or a #gw-link: link was lost or doubled.'], $violations);
    }

    public function test_a_layout_that_still_fails_is_reported_for_marking_stale(): void
    {
        $draft = Northfold::blocksDraft();
        unset($draft['page_builder'][0]['heading']);

        [, , $violations] = $this->edit($draft);

        $this->assertSame(['required: hero: heading is required.'], $violations);
    }
}
