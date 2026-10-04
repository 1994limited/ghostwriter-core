<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoFieldsContractTest;

/**
 * The contract against a small SEO addon of the test's own, in the shape
 * SEO Pro keeps: a custom string, `@seo:summary` from a field, null from
 * the cascade's template, and false when switched off. Plain fields are
 * checked beside it.
 */
final class PlainSeoFieldsContractTest extends SeoFieldsContractTest
{
    protected function seoFields(): SeoFields
    {
        return new class implements SeoFields
        {
            public function in(Schema $schema, EntryData $entry): array
            {
                $seo = $entry->get('seo');
                $description = is_array($seo) && array_key_exists('description', $seo) ? $seo['description'] : null;

                if ($description === false) {
                    return [];
                }

                $path = FieldPath::of('seo');

                if (is_string($description) && str_starts_with($description, '@seo:')) {
                    $from = substr($description, 5);

                    return [new SeoField($path, SeoField::DESCRIPTION, 'SEO description', 160, (string) $entry->get($from), true, ucfirst($from))];
                }

                return [new SeoField($path, SeoField::DESCRIPTION, 'SEO description', 160, is_string($description) ? $description : null, is_string($description))];
            }
        };
    }

    protected function seoEntry(string $state): array
    {
        $schema = new Schema([new Field('summary', Kind::LongText), new Field('seo', Kind::Group)]);
        $description = match ($state) {
            'custom' => 'Planting plans for borders and pots.',
            'field' => '@seo:summary',
            'template' => null,
            'disabled' => false,
        };

        return [$schema, new EntryData(['summary' => 'A short summary.', 'seo' => ['description' => $description]])];
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
}
