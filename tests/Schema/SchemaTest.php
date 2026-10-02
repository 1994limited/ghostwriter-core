<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Schema;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    /** A Craft field layout as its SchemaReader reads it: a Neo builder, a table, an assets field. */
    private const CRAFT = [
        ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title', 'instructions' => '', 'required' => true],
        ['handle' => 'hero', 'type' => 'craft\\fields\\Assets', 'kind' => 'reference', 'display' => 'Hero', 'instructions' => '', 'required' => false, 'max_files' => 1, 'sources' => ['volume:a'], 'source' => 'volume:a', 'images' => true],
        ['handle' => 'specs', 'type' => 'craft\\fields\\Table', 'kind' => 'rows', 'display' => 'Specs', 'instructions' => '', 'required' => false, 'fields' => [
            ['handle' => 'name', 'id' => 'col1', 'type' => 'table:singleline', 'kind' => 'text', 'display' => 'Name', 'instructions' => '', 'required' => false, 'options' => []],
        ], 'columns' => ['name' => 'col1']],
        ['handle' => 'pageBuilder', 'type' => 'benf\\neo\\Field', 'kind' => 'blocks', 'display' => 'Page builder', 'instructions' => '', 'required' => false, 'engine' => 'neo', 'sets' => [
            'hero' => ['display' => 'Hero', 'instructions' => 'Top of page', 'fields' => [
                ['handle' => 'children', 'type' => 'neo-children', 'kind' => 'blocks', 'engine' => 'neo-children', 'display' => 'Blocks inside', 'instructions' => '', 'required' => false, 'sets' => [
                    'text' => ['display' => 'Text', 'instructions' => '', 'fields' => [['handle' => 'richText', 'type' => 'craft\\ckeditor\\Field', 'kind' => 'richtext', 'display' => 'Rich text', 'instructions' => '', 'required' => false]]],
                ]],
            ]],
        ]],
        ['handle' => 'tone', 'type' => 'craft\\fields\\Dropdown', 'kind' => 'choice', 'display' => 'Tone', 'instructions' => 'How it sounds', 'required' => false, 'options' => ['warm' => 'Warm', '1' => 'One']],
    ];

    public function test_an_addons_spec_arrays_become_fields_and_go_back_the_same(): void
    {
        $schema = Schema::fromSpecs(self::CRAFT);

        $this->assertCount(5, $schema);
        $this->assertSame(['title', 'hero', 'specs', 'pageBuilder', 'tone'], array_map(fn (Field $field) => $field->handle, $schema->fields));

        $hero = $schema->field('hero');
        $this->assertNotNull($hero);
        $this->assertSame(Kind::Reference, $hero->kind);
        $this->assertTrue($hero->files);
        $this->assertSame(['max_files' => 1, 'sources' => ['volume:a'], 'source' => 'volume:a', 'images' => true], $hero->meta);

        $builder = $schema->field('pageBuilder');
        $this->assertNotNull($builder);
        $this->assertSame('neo', $builder->engine);
        $this->assertSame('Top of page', $builder->set('hero')?->instructions);
        $this->assertSame(Field::CHILDREN, $builder->set('hero')?->field('children')?->engine);
        $this->assertSame(['warm' => 'Warm', 1 => 'One'], $schema->field('tone')?->options);

        // Back to arrays: what code that still reads arrays relied on.
        $specs = $schema->toSpecs();
        $this->assertSame(self::CRAFT[0], $specs[0]);
        $this->assertSame(['name' => 'col1'], $specs[2]['columns']);
        $this->assertSame('col1', $specs[2]['fields'][0]['id']);
        $this->assertSame('richtext', $specs[3]['sets']['hero']['fields'][0]['sets']['text']['fields'][0]['kind']);
        $this->assertSame(Schema::fromSpecs($specs)->toArray(), $schema->toArray());
    }

    public function test_cores_own_array_form_round_trips_and_leaves_defaults_out(): void
    {
        $schema = Schema::fromSpecs(self::CRAFT);
        $array = $schema->toArray();

        $this->assertSame(['handle' => 'title', 'kind' => 'text', 'label' => 'Title', 'required' => true, 'type' => 'title'], $array[0]);
        $this->assertSame($array, Schema::fromArray($array)->toArray());
        $this->assertEquals($schema, Schema::fromArray($array));
    }

    public function test_builders_are_told_from_bard_and_from_rows(): void
    {
        $sets = ['text' => new Set('Text')];

        $this->assertTrue((new Field('body', Kind::Blocks))->isBuilder());
        // A Matrix with nothing to write in it is a reference, but still holds blocks.
        $this->assertTrue((new Field('gallery', Kind::Reference, sets: $sets))->isBuilder());
        // Bard keeps sets, but its value is a document.
        $this->assertFalse((new Field('body', Kind::RichText, sets: $sets))->isBuilder());
        $this->assertFalse((new Field('faqs', Kind::Rows, fields: [new Field('q', Kind::Text)]))->isBuilder());
        $this->assertFalse((new Field('image', Kind::Reference))->isBuilder());
    }

    public function test_kinds_know_what_is_written_set_and_copied(): void
    {
        $this->assertFalse(Kind::Reference->isWritable());
        $this->assertTrue(Kind::List->isWritable());
        $this->assertSame([Kind::Choice, Kind::Choices, Kind::Toggle, Kind::Number], array_values(array_filter(Kind::cases(), fn (Kind $kind) => $kind->isSetting())));
        $this->assertSame([Kind::List, Kind::Blocks, Kind::Rows, Kind::Group, Kind::Reference], array_values(array_filter(Kind::cases(), fn (Kind $kind) => $kind->isCopied())));

        $schema = Schema::fromSpecs(self::CRAFT);
        $this->assertSame(['title', 'specs', 'pageBuilder', 'tone'], array_map(fn (Field $field) => $field->handle, $schema->writable()));
    }

    public function test_a_field_with_a_kind_core_does_not_know_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "body" has no kind core knows: html.');

        Schema::fromSpecs([['handle' => 'body', 'kind' => 'html']]);
    }

    public function test_a_field_needs_a_handle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Field('', Kind::Text);
    }

    public function test_entry_data_knows_its_title_and_parent_and_round_trips(): void
    {
        $entry = new EntryData(['title' => 'About', 'body' => '<p>Hi</p>'], 12, parentId: 3, parentTitle: 'Studio');

        $this->assertSame('About', $entry->title());
        $this->assertSame('<p>Hi</p>', $entry->get('body'));
        $this->assertNull($entry->get('missing'));
        $this->assertSame('#4', (new EntryData(['name' => 'X'], 4, '#4'))->title());
        $this->assertEquals($entry, EntryData::fromArray($entry->toArray()));
        $this->assertSame(['id' => 12, 'parent' => ['id' => 3, 'title' => 'Studio'], 'values' => ['title' => 'About', 'body' => '<p>Hi</p>']], $entry->toArray());
    }

    public function test_with_changes_the_kind_or_meta_only(): void
    {
        $field = new Field('summary', Kind::LongText, 'Summary', meta: ['max_length' => 200]);
        $changed = $field->with(Kind::Reference, ['skipped' => true]);

        $this->assertSame(Kind::Reference, $changed->kind);
        $this->assertSame(['skipped' => true], $changed->meta);
        $this->assertSame('Summary', $changed->label);
        $this->assertSame(Kind::LongText, $field->kind);
    }
}
