<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Violation;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\MemoryAssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Required fields the writer never fills (an image, a link, entries, a
 * setting) don't drop a plan: its new blocks leave them empty and the
 * build path fills them as it fills the writer's draft. Only words a plan
 * leaves out fail "required".
 */
final class RequiredFieldsTest extends TestCase
{
    /** Two layouts that each make their own hero, as the Northfold planner reply did. */
    public const ALTERNATIVES = <<<'YAML'
        - name: Sections
          page_builder:
            - type: hero
              place: { heading: u2, subheading: u3 }
            - type: text
              place: { body: u4 }
            - type: text
              place: { body: u5 }
            - type: text
              place: { body: u6 }
            - type: cta
              place: { heading: u7, button: u8 }
        - name: Early call
          page_builder:
            - type: hero
              place: { heading: u2, subheading: u4 }
            - type: text
              place: { body: [u3, u5] }
            - type: cta
              place: { heading: u7, button: u8 }
            - type: text
              place: { body: u6 }
        YAML;

    /** A hero with no heading: words genuinely left out of a required text field. */
    public const NO_HEADING = <<<'YAML'
        - name: No heading
          page_builder:
            - type: hero
              place: { subheading: u3 }
            - type: text
              place: { body: [u2, u4, u5, u6] }
            - type: cta
              place: { heading: u7, button: u8 }
        YAML;

    /**
     * @return list<Plan>
     */
    private static function plans(string $yaml): array
    {
        return (new PlanReader)->read($yaml, Northfold::pages());
    }

    private static function layouts(): Layouts
    {
        return new Layouts(new LayoutOptions(linkSentinels: true), new HtmlDialect, new CraftLinks(link: ['craft\\fields\\Link']));
    }

    private static function pattern(): Pattern
    {
        return Pattern::fromArray(['blocks' => ['page_builder' => ['fixed' => ['hero' => ['style' => 'light']]]]]);
    }

    public function test_plans_with_their_own_hero_survive_its_required_image_link_entries_and_settings(): void
    {
        $schema = Northfold::pages();
        $draft = Northfold::pagesDraft();
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);
        [$sections, $early] = self::plans(self::ALTERNATIVES);

        $validator = new PlanValidator(self::layouts()->builder());

        $this->assertSame([], $validator->check($sections, $units, [], $draft, $schema, self::pattern(), [$writer]));
        $this->assertSame([], $validator->check($early, $units, [], $draft, $schema, self::pattern(), [$writer, $sections]));

        $validated = $validator->validate([$writer, $sections, $early], $units, [], $draft, $schema, self::pattern());
        $this->assertSame([$writer, $sections, $early], $validated->kept);
        $this->assertSame([], $validated->dropped);
    }

    public function test_the_fields_that_must_be_written_in_are_the_text_ones(): void
    {
        $required = fn (Kind $kind, bool $files = false) => PlanValidator::requiredWords(new Field('f', $kind, required: true, files: $files));

        foreach ([Kind::Text, Kind::LongText, Kind::RichText, Kind::List, Kind::Rows] as $kind) {
            $this->assertTrue($required($kind), $kind->value);
            $this->assertFalse(PlanValidator::requiredWords(new Field('f', $kind)), "{$kind->value}, optional");
        }

        foreach ([Kind::Reference, Kind::Choice, Kind::Choices, Kind::Toggle, Kind::Number, Kind::Blocks, Kind::Group] as $kind) {
            $this->assertFalse($required($kind), $kind->value);
        }

        $this->assertFalse($required(Kind::Reference, true), 'an image');
    }

    public function test_words_left_out_of_a_required_field_still_drop_a_plan_and_say_why(): void
    {
        $schema = Northfold::pages();
        $draft = Northfold::pagesDraft();
        $units = Units::fromDraft($draft, $schema);
        $writer = Plans::fromDraft($draft, $units, $schema);
        [$sections] = self::plans(self::ALTERNATIVES);
        [$headless] = self::plans(self::NO_HEADING);
        $broken = $headless->with(id: 'p2');

        $validated = (new PlanValidator(self::layouts()->builder()))->validate([$writer, $sections, $broken], $units, [], $draft, $schema, self::pattern());

        $this->assertSame([$writer, $sections], $validated->kept);
        $this->assertSame(['p2'], array_keys($validated->dropped));
        $this->assertSame(['p2' => [Violation::REQUIRED]], $validated->rules());
        $this->assertSame(['required: hero: heading is required.'], array_map('strval', $validated->dropped['p2']));
    }

    #[RequiresPhpExtension('gd')]
    public function test_an_alternative_builds_with_the_placeholder_and_sentinel_the_writers_draft_gets(): void
    {
        $schema = Northfold::pages();
        $draft = Northfold::pagesDraft();
        $units = Units::fromDraft($draft, $schema);
        [$sections, $early] = self::plans(self::ALTERNATIVES);

        $writer = self::useThisDraft($draft);
        $writerHero = $writer['page_builder'][0];

        $this->assertSame(['default/'.Placeholders::FILENAME], $writerHero['image'], 'the writer\'s draft gets the placeholder');
        $this->assertSame(Markers::linkUrl('Button'), $writerHero['button']['value'], 'and the link sentinel');

        foreach ([$sections, $early] as $plan) {
            $data = (new Arranger)->arrange($plan, $units, [], $draft, $schema);
            $hero = self::useThisDraft($data)['page_builder'][0];

            $this->assertSame('hero', $hero['type']);
            $this->assertSame($writerHero['image'], $hero['image'], "{$plan->name}: the same placeholder");
            $this->assertSame($writerHero['button'], $hero['button'], "{$plan->name}: the same sentinel");
            $this->assertSame('light', $hero['style'], "{$plan->name}: the house default");
            $this->assertArrayNotHasKey('related', $hero, "{$plan->name}: entries are left for a person, as in the draft");
            $this->assertArrayNotHasKey('wide', $hero, "{$plan->name}: the CMS's own default, as in the draft");
        }
    }

    public function test_compare_layouts_finds_no_difference_between_the_draft_and_layout_1_here(): void
    {
        $schema = Northfold::pages();
        $draft = Northfold::pagesDraft();
        $units = Units::fromDraft($draft, $schema);
        $builder = self::layouts()->builder();
        $dir = sys_get_temp_dir().'/gw-required-'.bin2hex(random_bytes(4));
        mkdir($dir);

        LayoutLog::start("{$dir}/draft.jsonl", 'pages');
        LayoutLog::record('build', $builder->build($draft, $schema, self::pattern()));
        LayoutLog::start("{$dir}/plan.jsonl", 'pages');
        LayoutLog::record('build', (new Arranger(keepUntouched: false))->build($builder, Plans::fromDraft($draft, $units, $schema), $units, [], $draft, $schema, self::pattern()));
        LayoutLog::stop();

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-layouts').' '.escapeshellarg("{$dir}/draft.jsonl").' '.escapeshellarg("{$dir}/plan.jsonl"), $output, $status);
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        $this->assertSame(['The layouts are the same.'], $output);
        $this->assertSame(0, $status);
    }

    /**
     * "Use this draft" on some draft data, as the Craft addon runs it:
     * EntryBuilder, HouseStyle, then the placeholders.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function useThisDraft(array $data): array
    {
        $schema = Northfold::pages();
        $layouts = self::layouts();
        $built = $layouts->builder()->build($data, $schema, self::pattern());
        $house = $layouts->houseStyle()->apply($built->data, $schema, $layouts->houseStyle()->learn([], $schema));

        return (new Placeholders(new MemoryAssetSink))->fill($house->data, $schema);
    }
}
