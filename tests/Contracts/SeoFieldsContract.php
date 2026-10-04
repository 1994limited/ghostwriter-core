<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * What every SeoFields must do, for each SEO addon an implementation
 * reads. The addon gives an entry in four states: a custom description,
 * one inherited from another field, one inherited from a template, and
 * one switched off. Each entry also has an SEO title, unless the addon
 * keeps none.
 */
trait SeoFieldsContract
{
    abstract protected function seoFields(): SeoFields;

    /**
     * @param  'custom'|'field'|'template'|'disabled'  $state
     * @return array{0: Schema, 1: EntryData}
     */
    abstract protected function seoEntry(string $state): array;

    private function description(string $state): ?SeoField
    {
        [$schema, $entry] = $this->seoEntry($state);

        foreach ($this->seoFields()->in($schema, $entry) as $field) {
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
        $this->assertGreaterThan(0, $field->limit);
    }

    public function test_a_description_inherited_from_a_field_is_that_fields_text(): void
    {
        $field = $this->description('field');

        $this->assertNotNull($field);
        $this->assertNotNull($field->text);
        $this->assertNotNull($field->inheritsFrom, 'It names the field it comes from.');
    }

    public function test_a_description_from_a_template_is_not_checked_or_written(): void
    {
        $field = $this->description('template');

        if ($field !== null) {
            $this->assertNull($field->text);
            $this->assertFalse($field->writable);
        }

        $this->assertTrue($field === null || ! $field->tooLong());
    }

    public function test_a_disabled_description_is_left_out(): void
    {
        $this->assertNull($this->description('disabled'));
    }
}
