<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Candidates;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SitePatterns;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use PHPUnit\Framework\TestCase;

/** The site's own shapes, and "Suggested": the layout most like them. */
final class CandidatesTest extends TestCase
{
    /**
     * @param  list<string>  $types
     */
    private static function page(int $id, string $title, array $types): EntryData
    {
        return new EntryData(['title' => $title, 'page_builder' => array_map(fn (string $type) => ['type' => $type], $types)], $id);
    }

    public function test_site_patterns_keep_every_order_of_blocks_commonest_first(): void
    {
        $entries = [
            self::page(9, 'Hedge laying', ['hero', 'cards', 'faq', 'cta']),
            self::page(8, 'Garden design', ['hero', 'text', 'cta']),
            self::page(7, 'Lawn care', ['hero', 'cards', 'faq', 'cta']),
            self::page(6, 'Ponds', ['hero', 'text', 'cta']),
            self::page(5, 'Orchards', ['hero', 'cards', 'faq', 'cta']),
            self::page(4, 'Contact', ['hero', 'text', 'cta']),
            new EntryData(['title' => 'Empty', 'page_builder' => []], 3),
        ];

        $patterns = (new SitePatterns)->find(Northfold::blocks(), $entries, 2);

        $this->assertSame([
            ['id' => 'p-1', 'field' => 'page_builder', 'sequence' => ['hero', 'cards', 'faq', 'cta'], 'count' => 3, 'share' => 0.5, 'example' => 'Hedge laying', 'exampleId' => 9],
            ['id' => 'p-2', 'field' => 'page_builder', 'sequence' => ['hero', 'text', 'cta'], 'count' => 3, 'share' => 0.5, 'example' => 'Garden design', 'exampleId' => 8],
        ], $patterns);
    }

    public function test_the_plan_closest_to_the_sites_pages_is_suggested_and_ties_go_to_the_writer(): void
    {
        $schema = Northfold::blocks();
        $draft = Northfold::blocksDraft();
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);
        $cards = ArrangerTest::plan(<<<'YAML'
            - name: Cards
              page_builder:
                - { type: hero, place: { heading: u3, subheading: u4, image: u5 } }
                - type: section
                  place: { heading: "u7#1" }
                  children:
                    - { type: text, place: { body: [u6, "u7#2", "u7#3", u8] } }
                - { type: faq, place: { questions: [x2.1] } }
                - { type: cta, place: { heading: u9, button: u10 } }
            YAML, $schema);
        $plans = Plans::of($writer, [$cards]);

        $this->assertSame(['w', 'p1'], array_map(fn ($plan) => $plan->id, $plans->all()));

        $cardsSite = [['id' => 'p-1', 'field' => 'page_builder', 'sequence' => ['hero', 'cards', 'faq', 'cta'], 'count' => 6, 'share' => 1.0, 'example' => 'Lawn care', 'exampleId' => 7]];
        $ranked = (new Candidates)->rank($plans, $cardsSite, [], $units, Northfold::extras(), $draft, $schema);
        $this->assertSame('p1', $ranked->suggested()?->id, 'hero, section, faq, cta is one step from the site\'s order; the writer\'s is two');

        $this->assertSame('w', (new Candidates)->rank($plans, [], [], $units, [], $draft, $schema)->suggested()?->id, 'with nothing to go on, the writer\'s');
        $this->assertSame(0.25, Candidates::levenshtein(['hero', 'section', 'faq', 'cta'], ['hero', 'cards', 'faq', 'cta']));
    }

    public function test_rich_text_is_suggested_by_its_structure(): void
    {
        $schema = Northfold::richText();
        $draft = Northfold::richTextDraft();
        $units = Units::fromDraft($draft, $schema);
        $flowing = ArrangerTest::plan("- name: Flowing\n  body:\n    - { type: text, from: u2 }\n    - { type: p, from: [u3, u4], transform: heading-to-lead-in }\n", $schema);
        $plans = Plans::of(Plans::fromDraft($draft, $units, $schema), [$flowing]);

        $entries = [new EntryData(['title' => 'Essay', 'body' => '<p>'.str_repeat('Long flowing prose with no headings at all. ', 30).'</p>'], 1)];
        $profile = (new SitePatterns)->profile($schema, $entries);

        $this->assertSame(['body' => ['headings' => 0.0, 'lists' => 0.0, 'quotes' => 0.0, 'entries' => 1]], $profile);
        $this->assertSame('p1', (new Candidates)->rank($plans, [], $profile, $units, [], $draft, $schema)->suggested()?->id);
    }
}
