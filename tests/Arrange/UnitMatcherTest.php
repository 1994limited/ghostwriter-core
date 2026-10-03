<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitMatcher;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use PHPUnit\Framework\TestCase;

final class UnitMatcherTest extends TestCase
{
    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        return NormalisedText::words($text);
    }

    public function test_ids_survive_rewording(): void
    {
        $before = Units::fromDraft(UnitsTest::draft(), UnitsTest::schema());
        $draft = UnitsTest::draft();
        $draft['intro'] = 'Four visits between November and February, all year round.';
        $draft['page_builder'][1]['text'] = str_replace('Gardens with mixed borders.', 'Gardens with mixed borders and young trees.', UnitsTest::BODY);
        $after = (new UnitMatcher)->carry($before, Units::fromDraft($draft, UnitsTest::schema()));

        $this->assertSame($before->ids(), $after->ids());
        $this->assertSame(11, $after->next);
    }

    public function test_ids_follow_reordered_blocks(): void
    {
        $before = Units::fromDraft(UnitsTest::draft(), UnitsTest::schema());
        $draft = UnitsTest::draft();
        $draft['page_builder'] = array_reverse($draft['page_builder']);
        $after = (new UnitMatcher)->carry($before, Units::fromDraft($draft, UnitsTest::schema()));

        $byText = fn (Units $units) => array_combine(array_map(fn (Unit $unit) => $unit->kind->value.$unit->markdown, $units->all()), $units->ids());

        $this->assertEquals($byText($before), $byText($after));
    }

    public function test_a_split_unit_keeps_its_id_on_the_bigger_half_and_the_rest_are_new(): void
    {
        $schema = new Schema([new Field('body', Kind::Blocks, sets: ['text' => new Set('Text', '', [new Field('text', Kind::LongText)])])]);
        $whole = 'Prune the shrubs that need it and wrap the tender plants. Leave the seedheads standing for the birds over the winter.';
        $before = Units::fromDraft(['body' => [['type' => 'text', 'text' => $whole]]], $schema);
        $after = (new UnitMatcher)->carry($before, Units::fromDraft(['body' => [
            ['type' => 'text', 'text' => 'Prune the shrubs that need it.'],
            ['type' => 'text', 'text' => 'Leave the seedheads standing for the birds over the winter, and wrap the tender plants.'],
        ]], $schema));

        $this->assertSame(['u2', 'u1'], $after->ids());
        $this->assertSame(3, $after->next);
    }

    public function test_a_rewritten_unit_gets_a_new_id_and_ids_are_never_reused(): void
    {
        $schema = new Schema([new Field('intro', Kind::LongText)]);
        $before = Units::of([new Unit('u7', UnitKind::Prose, FieldPath::of('intro'), 'Four visits between November and February.')], 9);
        $after = (new UnitMatcher)->carry($before, Units::fromDraft(['intro' => 'A completely different opening about summer planting plans.'], $schema));

        $this->assertSame(['u9'], $after->ids());
        $this->assertSame(10, $after->next);
    }

    public function test_media_matches_by_its_assets(): void
    {
        $before = Units::fromDraft(UnitsTest::draft(), UnitsTest::schema());
        $draft = UnitsTest::draft();
        $draft['page_builder'][0]['image'] = 'assets::other.jpg';
        $after = (new UnitMatcher)->carry($before, Units::fromDraft($draft, UnitsTest::schema()));

        $this->assertSame('u11', $after->all()[3]->id, 'another image is another unit');
        $this->assertSame(1.0, UnitMatcher::similarity($before->all()[3], $before->all()[3]));
        $this->assertSame(0.0, UnitMatcher::similarity($before->all()[3], $before->all()[2]), 'media never matches text');
    }
}
