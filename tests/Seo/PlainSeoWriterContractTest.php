<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\PlainSeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SeoWriterContractTest;

/**
 * SeoWriterContract on core's own writer for plain SEO fields: they have
 * no inherited, templated or switched-off states.
 */
final class PlainSeoWriterContractTest extends SeoWriterContractTest
{
    protected function seoWriterFields(): SeoFields
    {
        return new PlainSeoFields;
    }

    protected function seoWriter(): SeoWriter
    {
        return new PlainSeoWriter;
    }

    protected function seoWriterEntry(string $state): ?array
    {
        $schema = new Schema([
            new Field('title', Kind::Text, 'Title'),
            new Field('seo_title', Kind::Text, 'SEO title', meta: ['character_limit' => 60]),
            new Field('meta_description', Kind::LongText, 'Meta description', meta: ['character_limit' => 160]),
        ]);

        return match ($state) {
            'empty' => [$schema, new EntryData(['title' => 'Winter care', 'seo_title' => '', 'meta_description' => ''])],
            'custom' => [$schema, new EntryData(['title' => 'Winter care', 'seo_title' => 'Winter care', 'meta_description' => 'Our own words about winter visits, written by the editor for this page and nobody else.'])],
            default => null,
        };
    }
}
