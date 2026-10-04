<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssetAlt;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\TestCase;

/** MissingAlt and SeoLength, Finish this page's two new suggestions. */
final class DetectorsTest extends TestCase
{
    public function test_missing_alt_in_image_fields_and_rich_text_but_not_the_placeholder(): void
    {
        $schema = new Schema([
            new Field('hero', Kind::Reference, 'Hero image', files: true),
            new Field('body', Kind::RichText, 'Body'),
            new Field('logo', Kind::Reference, 'Logo', files: true),
            new Field('spare', Kind::Reference, 'Spare', files: true),
        ]);
        $entry = new EntryData([
            'hero' => ['photos::rocks.jpg', 'photos::path.jpg'],
            'body' => '<p>Look:</p><img src="asset::photos::materials.jpg">',
            'logo' => 'uploads::logo.png',
            'spare' => 'photos::ghostwriter-image-placeholder.png',
        ]);
        $assets = new MemoryAssets;
        $context = new GapContext(schema: $schema, entry: $entry, placeholders: $assets, assets: $assets, alt: new MemoryAssetAlt(['photos::path.jpg' => 'A path'], ['uploads']));

        $gaps = GapFinder::standard()->find($context)->ofKind(GapKind::MissingAlt);

        $this->assertSame(['missing-alt|hero|photos::rocks.jpg|0', 'missing-alt|body|photos::materials.jpg|0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame(Severity::Suggestion, $gaps[0]->severity);
        $this->assertFalse($gaps[0]->counts(), 'Never counted in the pill.');
        $this->assertSame('rocks.jpg', $gaps[0]->meta['filename']);

        $this->assertSame([], GapFinder::standard()->find(new GapContext(schema: $schema, entry: $entry, assets: $assets))->ofKind(GapKind::MissingAlt), 'No AssetAlt, nothing.');
    }

    public function test_seo_length_over_the_limit_only(): void
    {
        $schema = new Schema([new Field('seo_title', Kind::Text, 'SEO title'), new Field('seo_description', Kind::LongText, 'SEO description')]);
        $entry = new EntryData(['seo_title' => 'Services', 'seo_description' => str_repeat('word ', 40)]);

        $gaps = GapFinder::standard()->find(new GapContext(schema: $schema, entry: $entry, seo: new PlainSeoFields))->ofKind(GapKind::SeoLength);

        $this->assertCount(1, $gaps);
        $this->assertSame('description', $gaps[0]->meta['role']);
        $this->assertSame(160, $gaps[0]->meta['limit']);
        $this->assertSame(199, $gaps[0]->meta['length']);
        $this->assertSame('SEO description is too long.', $gaps[0]->message()->english());
        $this->assertSame([], GapFinder::standard()->find(new GapContext(schema: $schema, entry: $entry))->ofKind(GapKind::SeoLength), 'No SeoFields, nothing.');
    }
}
