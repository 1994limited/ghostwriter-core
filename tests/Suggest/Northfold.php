<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryLinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestOptions;

/**
 * The mockup's Services page on Northfold, in Statamic's shapes: a page
 * builder with a hero, a text block and an image, and an SEO description
 * of 195 characters. Last saved on 14 March 2024; "now" is 4 October 2026.
 */
final class Northfold
{
    public const NOW = '2026-10-04 10:00:00';

    public const UPDATED = '2024-03-14';

    public const TEXT = "## Garden design\n\nA full design for your garden from our team of 6 designers: a survey, a concept, a detailed layout and construction drawings, and a planting plan. In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept. See [our 2023 show garden](entry::e31).";

    public const SEO = "From a single planting plan to a full design and build, Northfold can be as involved as you'd like, across Northumberland, Durham and the Tyne Valley, with consultations, planting plans and more.";

    public static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title'),
            new Field('page_builder', Kind::Blocks, 'Page Builder', sets: [
                'hero' => new Set('Hero', fields: [new Field('eyebrow', Kind::Text, 'Eyebrow'), new Field('heading', Kind::Text, 'Heading')]),
                'text' => new Set('Text', fields: [new Field('text', Kind::LongText, 'Text', type: 'markdown')]),
                'image' => new Set('Image', fields: [new Field('image', Kind::Reference, 'Image', type: 'assets', files: true)]),
            ]),
            new Field('seo_description', Kind::LongText, 'SEO description'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes  Top-level values to replace.
     */
    public static function entry(array $changes = []): EntryData
    {
        return new EntryData($changes + [
            'title' => 'Services',
            'page_builder' => [
                ['id' => 'h1', 'type' => 'hero', 'eyebrow' => 'New for 2024: winter care visits', 'heading' => 'We leverage our expertise to deliver bespoke garden solutions'],
                ['id' => 't1', 'type' => 'text', 'text' => self::TEXT],
                ['id' => 'i1', 'type' => 'image', 'image' => 'assets::materials.jpg'],
            ],
            'seo_description' => self::SEO,
        ], 'services');
    }

    public static function ref(): EntryRef
    {
        return new EntryRef('pages', 'services', 'default');
    }

    public static function context(?EntryData $entry = null, ?Quieted $quieted = null, ?EntryIndex $index = null, ?string $now = null, ?SuggestOptions $options = null, ?AgePolicy $age = null, string $updated = self::UPDATED, ?SeoFields $seo = null): CheckContext
    {
        $assets = new MemoryAssets;

        return new CheckContext(
            gaps: new GapContext(
                schema: self::schema(),
                entry: $entry ?? self::entry(),
                links: new StatamicLinks,
                placeholders: $assets,
                assets: $assets,
                targets: new MemoryLinkTargets(['e12' => ['title' => 'A walled garden in Corbridge, two years on', 'slug' => 'walled-garden-corbridge', 'url' => '/journal/walled-garden-corbridge']]),
                alt: new MemoryAssetAlt,
                seo: $seo ?? new PlainSeoFields,
            ),
            now: new DateTimeImmutable($now ?? self::NOW),
            updatedAt: new DateTimeImmutable($updated),
            language: 'en',
            index: $index,
            entry: self::ref(),
            age: $age ?? new AgePolicy,
            options: $options ?? new SuggestOptions,
            quieted: $quieted ?? new Quieted,
        );
    }
}
