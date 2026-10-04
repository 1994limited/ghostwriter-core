<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * What every SeoFields must do, for each SEO addon an implementation
 * reads. The addon gives an entry in each of these states, in the shapes
 * its addon really stores (captured from a site, anonymised):
 *
 * - `custom`: a description of the page's own;
 * - `field`: the entry's description set to another field's text;
 * - `template`: one from a template core can't evaluate;
 * - `disabled`: one switched off;
 * - `section`: nothing set on the entry, so the section's default applies,
 *   and it takes another field's text (SEO Pro's `inject.seo`, a SEOmatic
 *   section bundle with `fromField`). Null where the addon has no section
 *   defaults.
 * - `noindex`: a page that asks not to be indexed (null where nothing can
 *   say so); `custom` must not.
 *
 * Each entry also has an SEO title, unless the addon keeps none.
 */
trait SeoFieldsContract
{
    abstract protected function seoFields(): SeoFields;

    /**
     * @param  'custom'|'field'|'template'|'disabled'|'section'|'noindex'  $state
     * @return array{0: Schema, 1: EntryData}|null Null for a state the addon can't have.
     */
    abstract protected function seoEntry(string $state): ?array;

    /**
     * The `<title>` the page prints for an SEO title, as the addon composes
     * it for the `custom` entry ("Services | Northfold"); null where nothing
     * adds a site name.
     *
     * @return array{0: string, 1: string}|null [SEO title, the page's title]
     */
    abstract protected function seoTitle(): ?array;

    private function description(string $state): ?SeoField
    {
        $entry = $this->seoEntry($state);

        if ($entry === null) {
            $this->markTestSkipped("No {$state} state for this addon.");
        }

        foreach ($this->seoFields()->in(...$entry) as $field) {
            if ($field->role === SeoField::DESCRIPTION) {
                return $field;
            }
        }

        return null;
    }

    public function test_a_custom_description_is_its_own_text_and_writable(): void
    {
        $field = $this->description('custom');

        $this->assertNotNull($field);
        $this->assertNotSame('', (string) $field->text);
        $this->assertTrue($field->writable);
        $this->assertNull($field->inheritsFrom);
        $this->assertSame(SeoSource::Custom, $field->source);
        $this->assertFalse($field->inherited());
        $this->assertGreaterThan(0, $field->limit);
    }

    public function test_a_description_inherited_from_a_field_is_that_fields_text(): void
    {
        $field = $this->description('field');

        $this->assertNotNull($field);
        $this->assertNotNull($field->text);
        $this->assertNotSame('', trim($field->text), 'The source field has text.');
        $this->assertNotNull($field->inheritsFrom, 'It names the field it comes from.');
        $this->assertSame(SeoSource::Field, $field->source);
        $this->assertTrue($field->inherited());
        $this->assertFalse($field->isEmpty());
    }

    public function test_a_description_from_a_template_is_not_checked_or_written(): void
    {
        $field = $this->description('template');

        if ($field !== null) {
            $this->assertNull($field->text);
            $this->assertFalse($field->writable);
            $this->assertSame(SeoSource::Template, $field->source);
            $this->assertFalse($field->checkable());
            $this->assertFalse($field->isEmpty(), 'Not empty: the page prints what the template makes.');
        }

        $this->assertTrue($field === null || ! $field->tooLong());
    }

    public function test_a_disabled_description_is_switched_off_or_left_out(): void
    {
        $field = $this->description('disabled');

        if ($field !== null) {
            $this->assertSame(SeoSource::Disabled, $field->source);
            $this->assertNull($field->text);
            $this->assertFalse($field->writable);
            $this->assertFalse($field->checkable());
            $this->assertFalse($field->isEmpty());
        }

        $this->assertTrue($field === null || $field->source === SeoSource::Disabled);
    }

    public function test_nothing_on_the_entry_takes_the_sections_default(): void
    {
        $field = $this->description('section');

        $this->assertNotNull($field);
        $this->assertNotSame(SeoSource::Custom, $field->source, 'Not an empty value of the page\'s own: the section\'s default applies.');
        $this->assertSame(SeoSource::Field, $field->source);
        $this->assertNotNull($field->inheritsFrom);
        $this->assertNotSame('', trim((string) $field->text), 'The text the page prints, from the field the section names.');
        $this->assertFalse($field->isEmpty());
    }

    public function test_noindex_is_read_from_the_setting(): void
    {
        $noindex = $this->seoEntry('noindex');
        $custom = $this->seoEntry('custom');
        $this->assertNotNull($custom);

        $this->assertNotTrue($this->seoFields()->noindex(...$custom), 'A page with no robots setting is indexed.');

        if ($noindex === null) {
            $this->assertNull($this->seoFields()->noindex(...$custom), 'Nothing here can say so.');

            return;
        }

        $this->assertTrue($this->seoFields()->noindex(...$noindex));
    }

    public function test_the_title_format_composes_the_pages_title(): void
    {
        $custom = $this->seoEntry('custom');
        $this->assertNotNull($custom);
        $format = $this->seoFields()->titleFormat(...$custom);
        $expected = $this->seoTitle();

        if ($expected === null) {
            $this->assertTrue($format === null || ! $format->addsName());

            return;
        }

        $this->assertNotNull($format);
        $this->assertSame($expected[1], $format->compose($expected[0]));
        $this->assertSame(mb_strlen($expected[1]) - mb_strlen($expected[0]), $format->added());
    }
}
