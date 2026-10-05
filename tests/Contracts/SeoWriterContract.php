<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaAction;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchFields;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchMeta;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoWriter;

/**
 * What every SeoWriter must do (SEO layer §9.3, §9.4), for each SEO addon
 * an implementation writes, through core's SearchFields as "Use this draft"
 * does. The addon gives an entry in each state, in the shapes its addon
 * really stores (as for SeoFieldsContract):
 *
 * - `empty`: no SEO description of the page's own, and nothing inherited;
 * - `custom`: a description a person wrote;
 * - `field`: one inherited from another field of the entry, which has text;
 * - `template`: one from a template core can't evaluate (null where the
 *   addon has none);
 * - `disabled`: one switched off (null where the addon can't).
 *
 * Written text must read back through the addon's SeoFields as the page's
 * own (custom) text, and a person's value is never replaced.
 */
trait SeoWriterContract
{
    abstract protected function seoWriterFields(): SeoFields;

    abstract protected function seoWriter(): SeoWriter;

    /**
     * @param  'empty'|'custom'|'field'|'template'|'disabled'  $state
     * @return array{0: Schema, 1: EntryData}|null Null for a state the addon can't have.
     */
    abstract protected function seoWriterEntry(string $state): ?array;

    /**
     * The entry SeoFields reads once SeoWriter has written $values: by
     * default the same entry with those values. Override where the form's
     * values and what SeoFields reads differ in shape.
     *
     * @param  array<string, mixed>  $values
     */
    protected function seoWrittenEntry(array $values, EntryData $entry): EntryData
    {
        return $entry->withValues($values);
    }

    /** A description inside every range (120–155 for a 160 limit), with nothing a page might not say. */
    protected function seoWriterText(): string
    {
        return 'Monthly winter visits to cut back, divide and mulch established gardens, from November to February, so the borders come back strong in spring.';
    }

    /**
     * @return array{0: Schema, 1: EntryData}
     */
    private function writerEntry(string $state): array
    {
        $entry = $this->seoWriterEntry($state);

        if ($entry === null) {
            $this->markTestSkipped("No {$state} state for this addon.");
        }

        return $entry;
    }

    private function writerField(Schema $schema, EntryData $entry, string $role = SeoField::DESCRIPTION): ?SeoField
    {
        foreach ($this->seoWriterFields()->in($schema, $entry) as $field) {
            if ($field->role === $role) {
                return $field;
            }
        }

        return null;
    }

    private function applyText(string $state, SeoProvenance $provenance = new SeoProvenance, bool $newEntry = false, ?string $text = null): array
    {
        [$schema, $entry] = $this->writerEntry($state);
        $seo = new SeoState(meta: new SearchMeta(description: $text ?? $this->seoWriterText()));
        $applied = (new SearchFields($this->seoWriterFields(), $this->seoWriter()))->apply($entry->values, $schema, $entry, $seo, $newEntry, $provenance);

        return [$schema, $entry, $applied];
    }

    public function test_written_text_reads_back_as_the_pages_own(): void
    {
        [$schema, $entry] = $this->writerEntry('empty');
        $field = $this->writerField($schema, $entry);
        $this->assertNotNull($field, 'The empty entry has an SEO description field.');
        $this->assertTrue($field->writable);

        $values = $this->seoWriter()->write($entry->values, $field, $this->seoWriterText());
        $read = $this->writerField($schema, $this->seoWrittenEntry($values, $entry));

        $this->assertNotNull($read);
        $this->assertSame(SeoSource::Custom, $read->source);
        $this->assertSame($this->seoWriterText(), $read->text);
        $this->assertTrue($read->writable);
    }

    public function test_a_title_written_reads_back_and_leaves_the_description(): void
    {
        [$schema, $entry] = $this->writerEntry('custom');
        $title = $this->writerField($schema, $entry, SeoField::TITLE);

        if ($title === null || ! $title->writable) {
            $this->markTestSkipped('No writable SEO title for this addon.');
        }

        $before = $this->writerField($schema, $entry);
        $values = $this->seoWriter()->write($entry->values, $title, 'Winter garden care visits');
        $written = $this->seoWrittenEntry($values, $entry);

        $this->assertSame('Winter garden care visits', $this->writerField($schema, $written, SeoField::TITLE)?->text);
        $this->assertSame(SeoSource::Custom, $this->writerField($schema, $written, SeoField::TITLE)?->source);
        $this->assertSame($before?->text, $this->writerField($schema, $written)?->text, 'The description is as it was.');
    }

    public function test_an_empty_description_is_written_on_use_this_draft(): void
    {
        [$schema, $entry, $applied] = $this->applyText('empty', newEntry: true);

        $this->assertSame(MetaAction::Write, $applied->actions[SeoField::DESCRIPTION] ?? null);
        $this->assertSame($this->seoWriterText(), $this->writerField($schema, $this->seoWrittenEntry($applied->values, $entry))?->text);
        $this->assertTrue($applied->written->owns(SeoField::DESCRIPTION, $this->seoWriterText()), 'What Ghostwriter wrote is known as its own.');
        $this->assertSame([], $applied->suggested);
    }

    public function test_a_persons_description_is_never_written(): void
    {
        [$schema, $entry, $applied] = $this->applyText('custom');

        $this->assertSame(MetaAction::Suggest, $applied->actions[SeoField::DESCRIPTION] ?? null);
        $this->assertSame($entry->values, $applied->values, 'The values are untouched.');
        $this->assertSame($this->seoWriterText(), $applied->suggested[SeoField::DESCRIPTION] ?? null, 'It is suggested instead.');
        $this->assertTrue($applied->written->isEmpty());
    }

    public function test_ghostwriters_own_unchanged_description_is_written_again(): void
    {
        [$schema, $entry] = $this->writerEntry('custom');
        $own = (string) $this->writerField($schema, $entry)?->text;
        [, , $applied] = $this->applyText('custom', (new SeoProvenance)->with(SeoField::DESCRIPTION, $own));

        $this->assertSame(MetaAction::Write, $applied->actions[SeoField::DESCRIPTION] ?? null);
        $this->assertSame($this->seoWriterText(), $this->writerField($schema, $this->seoWrittenEntry($applied->values, $entry))?->text);
    }

    public function test_an_inherited_description_is_left_as_the_site_set_it_up(): void
    {
        [$schema, $entry, $applied] = $this->applyText('field', newEntry: true);
        $field = $this->writerField($schema, $entry);

        $this->assertNotNull($field);
        $this->assertTrue($field->inherited());
        $this->assertNotSame(MetaAction::Write, $applied->actions[SeoField::DESCRIPTION] ?? null, 'Inherited: left when it fits (decision 11), suggested when it doesn\'t.');
        $this->assertSame($entry->values, $applied->values);
    }

    public function test_a_templated_description_is_left(): void
    {
        [, $entry, $applied] = $this->applyText('template', newEntry: true);

        $this->assertSame($entry->values, $applied->values);
        $this->assertNotSame(MetaAction::Write, $applied->actions[SeoField::DESCRIPTION] ?? MetaAction::Leave);
    }

    public function test_a_switched_off_description_is_left(): void
    {
        [, $entry, $applied] = $this->applyText('disabled', newEntry: true);

        $this->assertSame($entry->values, $applied->values);
        $this->assertSame(MetaAction::Leave, $applied->actions[SeoField::DESCRIPTION] ?? MetaAction::Leave);
    }
}
