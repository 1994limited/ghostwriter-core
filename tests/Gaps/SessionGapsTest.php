<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use PHPUnit\Framework\TestCase;

class SessionGapsTest extends TestCase
{
    public function test_the_list_is_made_from_what_the_draft_left(): void
    {
        $built = new BuiltEntry([], [], [['path' => 'price', 'label' => 'Price', 'hint' => 'adult price']], [['path' => 'body/#b1/picture', 'label' => 'Offer: Picture']]);
        $gaps = SessionGaps::fromDraft($built, ['Call to action (links to example.com for now)', 'Crumbs: Link'], ['Featured image']);

        $this->assertSame([
            ['kind' => 'ask-value', 'path' => 'price', 'label' => 'Price', 'hint' => 'adult price', 'reason' => 'draft'],
            ['kind' => 'place', 'path' => 'body/#b1/picture', 'label' => 'Offer: Picture', 'reason' => 'draft'],
            ['kind' => 'place', 'label' => 'Call to action', 'reason' => 'draft'],
            ['kind' => 'place', 'label' => 'Crumbs: Link', 'reason' => 'draft'],
            ['kind' => 'image-placeholder', 'label' => 'Featured image', 'reason' => 'draft'],
        ], $gaps->toArray());

        $this->assertSame([['path' => 'price', 'label' => 'Price', 'hint' => 'adult price']], $gaps->askValues());
        $this->assertTrue($gaps->expects(FieldPath::parse('body/#b1/picture')));
        $this->assertTrue($gaps->expects(FieldPath::of('crumbs'), 'Crumbs: Link'));
        $this->assertFalse($gaps->expects(FieldPath::of('summary'), 'Summary'));
    }

    public function test_a_gap_is_enriched_by_path_or_by_label_and_never_trusted_over_what_is_found(): void
    {
        $gaps = new SessionGaps([
            ['kind' => 'ask', 'path' => 'intro', 'hint' => 'Adult  Price', 'reason' => 'draft'],
            ['kind' => 'image-placeholder', 'label' => 'Featured image'],
            ['kind' => 'place', 'path' => 'body/#b1/picture'],
            'not an entry',
            ['path' => 'no kind'],
        ]);

        $this->assertCount(3, $gaps->entries);
        $this->assertTrue($gaps->enrich(Gap::make(GapKind::Ask, FieldPath::of('intro'), 'Intro', 'adult price'))->meta['fromDraft']);
        $this->assertArrayNotHasKey('fromDraft', $gaps->enrich(Gap::make(GapKind::Ask, FieldPath::of('intro'), 'Intro', 'child price'))->meta);
        $this->assertSame('draft', $gaps->enrich(Gap::make(GapKind::ImagePlaceholder, FieldPath::of('featured_image'), 'Featured image'))->meta['reason']);
        $this->assertTrue($gaps->enrich(Gap::make(GapKind::ImageEmpty, FieldPath::parse('body/#b1/picture'), 'Offer: Picture'))->meta['fromDraft']);
    }

    public function test_the_session_keeps_its_gap_list_only_once_it_has_one(): void
    {
        foreach ([Format::Statamic, Format::Craft, Format::Filament] as $format) {
            $session = Session::start($format, 'news', []);

            $this->assertArrayNotHasKey('gaps', $session->toArray(), 'A store with no place for it is never sent the key.');

            $session->gaps = (new SessionGaps([['kind' => 'ask-value', 'path' => 'price', 'hint' => 'price']]))->toArray();
            $stored = $session->toArray();
            $read = Session::fromArray($stored, $format);

            $this->assertSame([['kind' => 'ask-value', 'path' => 'price', 'hint' => 'price']], $read->gaps);
            $this->assertSame($format === Format::Filament ? '[{"kind":"ask-value","path":"price","hint":"price"}]' : [['kind' => 'ask-value', 'path' => 'price', 'hint' => 'price']], $stored['gaps']);
            $this->assertSame($stored, $read->toArray(), 'Read and saved untouched, it is written back as it was.');

            $read->gaps = [];
            $this->assertSame($format === Format::Filament ? null : [], $read->toArray()['gaps'], 'Once there, it can be emptied.');
        }
    }
}
