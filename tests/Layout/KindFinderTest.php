<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\FoundKind;
use NineteenNinetyFour\Ghostwriter\Core\Layout\KindFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

class KindFinderTest extends LayoutTestCase
{
    /**
     * @param  array<int, string>  $blocks
     */
    private static function page(int $id, string $title, array $blocks, ?int $parent = null, ?string $parentTitle = null): EntryData
    {
        return new EntryData(['title' => $title, 'body' => array_map(fn (string $type) => ['type' => $type, 'enabled' => true], $blocks)], $id, parentId: $parent, parentTitle: $parentTitle);
    }

    /**
     * @return array<int, EntryData>
     */
    private static function pages(): array
    {
        return [
            self::page(1, 'Yacht studio', ['hero', 'text', 'quote'], 9, 'Studios'),
            self::page(2, 'Aviation studio', ['hero', 'text', 'quote', 'spacer'], 9, 'Studios'),
            self::page(3, 'Pricing', ['text', 'gallery']),
            self::page(4, 'Careers', ['text', 'gallery', 'spacer']),
            self::page(5, 'Press', ['text', 'gallery']),
            self::page(6, 'Contact', ['spacer']),
            self::page(7, 'Empty', []),
        ];
    }

    public function test_entries_built_the_same_way_are_one_kind(): void
    {
        $kinds = array_map(fn (FoundKind $kind) => $kind->toArray(), (new KindFinder)->find(self::articles(), self::pages()));

        $this->assertSame([
            ['label' => 'Like Pricing, Careers and 1 more', 'count' => 3, 'examples' => [3, 4, 5], 'titles' => ['Pricing', 'Careers', 'Press'], 'blocks' => ['text', 'gallery']],
            ['label' => 'Like the pages under Studios', 'count' => 2, 'examples' => [1, 2], 'titles' => ['Yacht studio', 'Aviation studio'], 'blocks' => ['hero', 'text', 'quote']],
        ], $kinds);
    }

    public function test_one_kind_covering_nearly_everything_is_just_the_group(): void
    {
        $pages = [self::page(1, 'A', ['hero', 'text']), self::page(2, 'B', ['hero', 'text']), self::page(3, 'C', ['hero', 'text', 'quote']), self::page(4, 'D', ['quote'])];

        $this->assertSame([], (new KindFinder)->find(self::articles(), $pages));
    }

    public function test_without_a_page_builder_there_are_no_kinds(): void
    {
        $this->assertSame([], (new KindFinder)->find(new Schema([new Field('title', Kind::Text)]), self::pages()));
    }

    public function test_a_translated_label_names_the_kind(): void
    {
        $options = LayoutOptions::filament(fn (array $titles, int $more) => 'Comme '.implode(', ', $titles).($more ? " et {$more} autres" : ''));
        $pages = array_map(fn (EntryData $entry) => new EntryData($entry->values, $entry->id), self::pages());

        $labels = array_map(fn (FoundKind $kind) => $kind->label, (new KindFinder($options))->find(self::articles(), $pages));

        $this->assertSame(['Comme Pricing, Careers et 1 autres', 'Comme Yacht studio, Aviation studio'], $labels);
    }

    public function test_filament_finds_kinds_from_its_first_builder_even_with_nothing_to_write(): void
    {
        $gallery = new Field('gallery', Kind::Reference, sets: ['image' => new Set('Image'), 'video' => new Set('Video')]);
        $schema = new Schema([$gallery, ...self::articles()->fields]);
        $pages = [];

        foreach ([['image'], ['image'], ['video'], ['video']] as $i => $blocks) {
            $pages[] = new EntryData(['title' => "Page {$i}", 'gallery' => array_map(fn ($type) => ['type' => $type], $blocks), 'body' => [['type' => 'text']]], $i);
        }

        $this->assertSame([], (new KindFinder(LayoutOptions::craft()))->find($schema, $pages));
        $this->assertSame([['image'], ['video']], array_map(fn (FoundKind $kind) => $kind->blocks, (new KindFinder(LayoutOptions::filament()))->find($schema, $pages)));
    }
}
