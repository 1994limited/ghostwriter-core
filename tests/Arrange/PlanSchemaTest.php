<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanSchema;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Transform;
use PHPUnit\Framework\TestCase;

/**
 * The layout planner's structured reply: a schema within every provider's
 * limits however many blocks a site has, and the way back to the plans
 * PlanReader reads from YAML.
 */
final class PlanSchemaTest extends TestCase
{
    /**
     * @param  list<array{string, list<string>}>  $place
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    public static function block(string $type, array $place, array $children = [], string $transform = '', int $level = 0, array $transforms = [], array $rows = []): array
    {
        return [
            'type' => $type,
            'place' => array_map(fn (array $p) => ['field' => $p[0], 'refs' => $p[1]], $place),
            'rows' => $rows,
            'transform' => $transform,
            'level' => $level,
            'transforms' => array_map(fn (string $ref, string $t) => ['ref' => $ref, 'transform' => $t], array_keys($transforms), $transforms),
            'children' => $children,
        ];
    }

    public function test_the_schema_has_no_optional_property_no_union_and_no_recursion(): void
    {
        $schema = PlanSchema::for(Northfold::blocks(), 2);
        $claude = Schemas::anthropic($schema);
        $json = (string) json_encode($claude);

        [$optional, $unions] = self::tally($claude);
        $this->assertSame([0, 0], [$optional, $unions], 'Claude allows 24 optional properties and 16 unions in a request.');
        $this->assertStringNotContainsString('$ref', $json);
        $this->assertLessThan(12000, strlen($json), 'Small enough to compile quickly.');

        $block = $claude['properties']['plans']['items']['properties']['fields']['items']['properties']['blocks']['items'];
        $this->assertArrayHasKey('children', $block['properties']);
        $this->assertArrayNotHasKey('children', $block['properties']['children']['items']['properties'], 'As deep as the site\'s blocks nest (a section of cards), no deeper.');
        $this->assertSame('notes', array_key_first($claude['properties']['plans']['items']['properties']));
        $this->assertContains('hero', $block['properties']['type']['enum']);
        $this->assertContains('card', $block['properties']['type']['enum'], 'Nested sets too.');
        $this->assertSame(['page_builder'], array_values(array_intersect(['page_builder'], $claude['properties']['plans']['items']['properties']['fields']['items']['properties']['field']['enum'])));
    }

    public function test_a_structured_plan_reads_as_the_same_yaml_plan(): void
    {
        $yaml = <<<'YAML'
            - name: Scannable
              description: Cards
              follows: p-1
              page_builder:
                - type: hero
                  place: { heading: u3, subheading: u4, image: u5 }
                - type: section
                  place: { heading: "u7#1" }
                  transform: { "u7#1": heading-level }
                  level: 3
                  children:
                    - { type: card, place: { heading: "u7#2:lead", body: "u7#2:rest" } }
                - type: faq
                  rows: [{ question: x2.1.question, answer: x2.1.text }]
            YAML;
        $structured = ['notes' => 'Cards.', 'name' => 'Scannable', 'description' => 'Cards', 'follows' => 'p-1', 'fields' => [[
            'field' => 'page_builder',
            'blocks' => [
                self::block('hero', [['heading', ['u3']], ['subheading', ['u4']], ['image', ['u5']]]),
                self::block('section', [['heading', ['u7#1']]], [self::block('card', [['heading', ['u7#2:lead']], ['body', ['u7#2:rest']]])], level: 3, transforms: ['u7#1' => 'heading-level']),
                self::block('faq', [], rows: [['field' => '', 'cells' => [['column' => 'question', 'ref' => 'x2.1.question'], ['column' => 'answer', 'ref' => 'x2.1.text']]]]),
            ],
            'constructs' => [],
            'refs' => [],
        ]]];

        $reader = new PlanReader;
        $fromYaml = $reader->read($yaml, Northfold::blocks());
        $fromJson = $reader->readList([PlanSchema::toRaw($structured, Northfold::blocks())], Northfold::blocks());

        $this->assertCount(1, $fromJson);
        $this->assertEquals($fromYaml, $fromJson);
    }

    public function test_an_empty_transform_and_unknown_fields_are_left_out(): void
    {
        $raw = PlanSchema::toRaw(['name' => 'A', 'description' => '', 'follows' => '', 'fields' => [
            ['field' => 'nope', 'blocks' => [], 'constructs' => [], 'refs' => ['u1']],
            ['field' => 'page_builder', 'blocks' => [self::block('text', [['body', ['u6']]])], 'constructs' => [], 'refs' => []],
        ]], Northfold::blocks());

        $this->assertSame(['name', 'description', 'follows', 'page_builder'], array_keys($raw));
        $this->assertSame([['type' => 'text', 'place' => ['body' => ['u6']]]], $raw['page_builder']);
    }

    public function test_the_transforms_offered_are_the_ones_the_planner_may_use(): void
    {
        $schema = PlanSchema::for(Northfold::blocks(), 2)->schema;
        $enum = $schema['properties']['plans']['items']['properties']['fields']['items']['properties']['blocks']['items']['properties']['transform']['enum'];

        $this->assertSame(['', 'lead-in-to-heading', 'heading-to-lead-in', 'paragraphs-to-list', 'list-to-paragraphs', 'heading-level', 'as-quote'], $enum);
        $this->assertNotContains(Transform::Split->value, $enum);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{int, int}
     */
    private static function tally(array $node): array
    {
        $optional = 0;
        $unions = 0;

        foreach (is_array($node['properties'] ?? null) ? $node['properties'] : [] as $name => $child) {
            $optional += in_array($name, $node['required'] ?? [], true) ? 0 : 1;
            $unions += (is_array($child['type'] ?? null) || isset($child['anyOf'])) ? 1 : 0;
            [$o, $u] = self::tally($child);
            $optional += $o;
            $unions += $u;
        }

        if (is_array($node['items'] ?? null)) {
            [$o, $u] = self::tally($node['items']);
            $optional += $o;
            $unions += $u;
        }

        return [$optional, $unions];
    }
}
