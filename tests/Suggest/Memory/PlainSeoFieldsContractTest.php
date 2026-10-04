<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContractTest;

/**
 * The contract against a small SEO addon of the test's own, in the shape
 * SEO Pro keeps: a custom string, `@seo:summary` from a field, null from
 * the cascade's template (or the Journal's `@seo:summary` default), and
 * false when switched off. Plain fields are checked beside it.
 */
final class PlainSeoFieldsContractTest extends SeoFieldsContractTest
{
    protected function seoFields(): SeoFields
    {
        return new class implements SeoFields
        {
            /** Section defaults, by group, as SEO Pro keeps them. */
            private const SECTIONS = ['journal' => ['description' => '@seo:summary']];

            public function in(Schema $schema, EntryData $entry): array
            {
                $seo = $entry->get('seo');
                $description = is_array($seo) && array_key_exists('description', $seo) ? $seo['description'] : null;
                $description ??= self::SECTIONS[$entry->group ?? '']['description'] ?? null;
                $path = FieldPath::of('seo');

                if ($description === false) {
                    return [new SeoField($path, SeoField::DESCRIPTION, 'SEO description', 160, null, false, source: SeoSource::Disabled)];
                }

                if (is_string($description) && str_starts_with($description, '@seo:')) {
                    $from = substr($description, 5);

                    return [new SeoField($path, SeoField::DESCRIPTION, 'SEO description', 160, (string) $entry->get($from), true, ucfirst($from))];
                }

                return [new SeoField($path, SeoField::DESCRIPTION, 'SEO description', 160, is_string($description) ? $description : null, is_string($description))];
            }

            public function noindex(Schema $schema, EntryData $entry): ?bool
            {
                return (new PlainSeoFields)->noindex($schema, $entry);
            }

            public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat
            {
                return TitleFormat::of('Northfold', '|', 'after');
            }
        };
    }

    protected function seoEntry(string $state): ?array
    {
        $schema = new Schema([new Field('summary', Kind::LongText), new Field('seo', Kind::Group), new Field('noindex', Kind::Toggle)]);
        $description = match ($state) {
            'custom', 'noindex' => 'Planting plans for borders and pots.',
            'field' => '@seo:summary',
            'template', 'section' => null,
            'disabled' => false,
        };

        return [$schema, new EntryData(
            ['summary' => 'A short summary.', 'seo' => array_filter(['description' => $description], fn ($value) => $value !== null), 'noindex' => $state === 'noindex'],
            group: $state === 'section' ? 'journal' : 'pages',
        )];
    }

    protected function seoTitle(): ?array
    {
        return ['Services', 'Services | Northfold'];
    }

    public function test_plain_fields_by_handle_with_their_own_limit(): void
    {
        $schema = new Schema([
            new Field('meta_title', Kind::Text, 'Meta title'),
            new Field('seo_description', Kind::LongText, 'SEO description', meta: ['maxLength' => 155]),
            new Field('description', Kind::LongText, 'Description'),
        ]);
        $fields = (new PlainSeoFields)->in($schema, new EntryData(['meta_title' => 'Services', 'seo_description' => str_repeat('x', 156)]));

        $this->assertSame(['title', 'description'], array_map(fn (SeoField $f) => $f->role, $fields));
        $this->assertSame([60, 155], array_map(fn (SeoField $f) => $f->limit, $fields));
        $this->assertTrue($fields[1]->tooLong());
        $this->assertFalse($fields[0]->tooLong());
    }

    public function test_plain_noindex_toggles_and_no_site_name(): void
    {
        $schema = new Schema([new Field('seo_noindex', Kind::Toggle), new Field('no_index_note', Kind::Toggle)]);
        $plain = new PlainSeoFields;

        $this->assertTrue($plain->noindex($schema, new EntryData(['seo_noindex' => '1'])));
        $this->assertFalse($plain->noindex($schema, new EntryData(['seo_noindex' => false])));
        $this->assertNull($plain->noindex(new Schema([new Field('no_index_note', Kind::Toggle)]), new EntryData(['no_index_note' => true])));
        $this->assertNull($plain->titleFormat($schema, new EntryData([])));
    }

    public function test_a_title_format_adds_the_name_on_its_side(): void
    {
        $this->assertSame('Northfold — Services', TitleFormat::of('Northfold', '—', 'before')->compose('Services'));
        $this->assertSame('Services', TitleFormat::of('Northfold', '|', 'none')->compose('Services'));
        $this->assertSame('Services', TitleFormat::of('', '|', 'after')->compose('Services'));
        $this->assertSame(0, TitleFormat::of('', '|', 'after')->added());
        $this->assertSame(12, TitleFormat::of('Northfold', '|', 'after')->added());
        $this->assertSame('after', TitleFormat::of('Northfold', '|', null)->position);
    }
}
