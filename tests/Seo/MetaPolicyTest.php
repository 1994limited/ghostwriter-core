<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaAction;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaRange;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every row of §9.4: a person's SEO text is never replaced without
 * asking; empty and Ghostwriter's own unchanged text are written; an
 * inherited text that fits is left (decision 11); templates and switched
 * off values are left.
 */
final class MetaPolicyTest extends TestCase
{
    private const FITS = 'Monthly winter visits to cut back, divide and mulch established gardens, from November to February, so the borders come back strong.';

    private static function description(?string $text, SeoSource $source = SeoSource::Custom, bool $writable = true, ?string $from = null): SeoField
    {
        return new SeoField(FieldPath::of('seo')->with('description'), SeoField::DESCRIPTION, 'Meta description', 160, $text, $writable, $from, $source);
    }

    /**
     * @return array<string, array{0: SeoField, 1: bool, 2: MetaAction, 3?: SeoProvenance}>
     */
    public static function rows(): array
    {
        $own = (new SeoProvenance)->with(SeoField::DESCRIPTION, 'Ghostwriter wrote this once, and nobody changed it since then at all.');

        return [
            'empty, new' => [self::description(''), true, MetaAction::Write],
            'empty, existing' => [self::description(''), false, MetaAction::Write],
            'a template it can override, new' => [self::description(null, SeoSource::Template, true), true, MetaAction::Write],
            'a template it can override, existing' => [self::description(null, SeoSource::Template, true), false, MetaAction::Suggest],
            'a template it can\'t override' => [self::description(null, SeoSource::Template, false), true, MetaAction::Leave],
            'inherited, fits, new' => [self::description(self::FITS, SeoSource::Field, true, 'Excerpt'), true, MetaAction::Leave],
            'inherited, fits, existing' => [self::description(self::FITS, SeoSource::Field, true, 'Excerpt'), false, MetaAction::Leave],
            'inherited from an empty field, new: written, as the page prints nothing' => [self::description('', SeoSource::Field, true, 'Excerpt'), true, MetaAction::Write],
            'inherited from an empty field, existing' => [self::description('', SeoSource::Field, true, 'Excerpt'), false, MetaAction::Write],
            'inherited, too short' => [self::description('Winter visits.', SeoSource::Field, true, 'Excerpt'), false, MetaAction::Suggest],
            'inherited, too long' => [self::description(str_repeat('Winter visits for borders. ', 10), SeoSource::Field, true, 'Excerpt'), true, MetaAction::Suggest],
            'a section default that fits' => [self::description(self::FITS, SeoSource::Default), true, MetaAction::Leave],
            'Ghostwriter\'s own, unchanged' => [self::description('Ghostwriter wrote this once, and nobody changed it since then at all.'), false, MetaAction::Write, $own],
            'Ghostwriter\'s own, changed by a person' => [self::description('Ghostwriter wrote this once, and a person changed it since then.'), false, MetaAction::Suggest, $own],
            'a person\'s, new' => [self::description('Our own words.'), true, MetaAction::Suggest],
            'a person\'s, existing' => [self::description(self::FITS), false, MetaAction::Suggest],
            'switched off' => [self::description(null, SeoSource::Disabled, false), true, MetaAction::Leave],
            'not writable' => [self::description('Fixed elsewhere', SeoSource::Custom, false), true, MetaAction::Leave],
        ];
    }

    #[DataProvider('rows')]
    public function test_each_row(SeoField $field, bool $new, MetaAction $expected, ?SeoProvenance $provenance = null): void
    {
        $this->assertSame($expected, (new MetaPolicy)->decide($field, $provenance ?? new SeoProvenance, $new));
    }

    public function test_an_inherited_title_that_fits_is_left_however_short(): void
    {
        $title = new SeoField(FieldPath::of('seo')->with('title'), SeoField::TITLE, 'SEO title', 60, 'Winter care', true, 'Title', SeoSource::Field);

        $this->assertSame(MetaAction::Leave, (new MetaPolicy)->decide($title, new SeoProvenance, true));
    }

    public function test_the_seo_title_is_only_wanted_when_the_page_title_is_too_long_with_the_site_name(): void
    {
        $policy = new MetaPolicy;
        $plain = MetaRange::for(SeoField::TITLE, 60);
        $named = MetaRange::for(SeoField::TITLE, 60, TitleFormat::of('Northfold Gardens', '|', 'after'));

        $this->assertFalse($policy->wantsTitle('Winter care visits', $plain));
        $this->assertFalse($policy->wantsTitle('Winter care visits', $named), '"Winter care visits | Northfold Gardens" is 38.');
        $this->assertTrue($policy->wantsTitle('Garden jobs for late February: pruning, mulching and sowing', $named));
        $this->assertFalse($policy->wantsTitle('Garden jobs for late February: pruning, mulching, sowing', $plain), '56 fits 60 with no name added.');
        $this->assertTrue($policy->wantsTitle(str_repeat('Long title ', 6), $plain));
    }

    public function test_the_ranges_follow_the_limit_and_the_site_name(): void
    {
        $this->assertSame([30, 52], [MetaRange::for(SeoField::TITLE, 60)->min, MetaRange::for(SeoField::TITLE, 60)->max]);
        $named = MetaRange::for(SeoField::TITLE, 60, TitleFormat::of('Northfold Gardens', '|', 'after'));
        $this->assertSame(60 - 20, $named->max, '" | Northfold Gardens" comes off the budget.');
        $this->assertSame(30, $named->min);
        $this->assertSame([120, 155], [MetaRange::for(SeoField::DESCRIPTION, 160)->min, MetaRange::for(SeoField::DESCRIPTION, 160)->max]);
        $this->assertSame([70, 95], [MetaRange::for(SeoField::DESCRIPTION, 100)->min, MetaRange::for(SeoField::DESCRIPTION, 100)->max]);
        $this->assertSame([120, 155], [MetaRange::for(SeoField::DESCRIPTION)->min, MetaRange::for(SeoField::DESCRIPTION)->max], 'No limit of its own: 160.');
    }

    public function test_provenance_is_by_role_and_ignores_spacing(): void
    {
        $provenance = (new SeoProvenance)->with(SeoField::DESCRIPTION, 'Winter visits  for gardens.');

        $this->assertTrue($provenance->owns(SeoField::DESCRIPTION, " Winter visits for\ngardens. "));
        $this->assertFalse($provenance->owns(SeoField::TITLE, 'Winter visits for gardens.'));
        $this->assertFalse($provenance->owns(SeoField::DESCRIPTION, ''));
        $this->assertEquals($provenance, SeoProvenance::fromArray($provenance->toArray()));
        $this->assertTrue($provenance->merge((new SeoProvenance)->with(SeoField::TITLE, 'Winter'))->owns(SeoField::TITLE, 'Winter'));
    }
}
