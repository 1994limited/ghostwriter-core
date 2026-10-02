<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;

class PatternFinderTest extends LayoutTestCase
{
    public function test_the_pattern_comes_from_the_entries(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), self::entries());
        $body = $pattern->blocks['body'];

        $this->assertSame(3, $pattern->entries);
        $this->assertSame(['hero', 'text', 'quote', 'spacer'], $body['sequence']);
        $this->assertSame(['hero' => 1.0, 'text' => 1.0, 'quote' => 1.0, 'spacer' => 1.0], $body['usage']);
        $this->assertSame(['background' => 'dark'], $body['fixed']['hero']);
        $this->assertSame(['quote' => 'Gardens take time.', 'person' => 'Priya'], $body['fixed']['quote']);
        $this->assertSame(['heading', 'background'], $body['used']['hero']);

        // The quote and the spacer are never written afresh; the hero is.
        $this->assertSame(['quote', 'spacer'], $body['boilerplate']);

        // The tone is writable, so never a house default; the summary differs.
        $this->assertSame([], $pattern->fixed);
        $this->assertSame(['title' => 1.0, 'summary' => 1.0, 'image' => 0.0, 'tone' => 1.0, 'body' => 1.0, 'hero.heading' => 1.0, 'hero.background' => 1.0, 'hero.picture' => 0.0, 'text.copy' => 1.0, 'quote.quote' => 1.0, 'quote.person' => 1.0, 'spacer.height' => 1.0], $pattern->filled);
        $this->assertGreaterThan(0, $pattern->words);
    }

    public function test_examples_are_the_newest_two_without_what_is_the_same_everywhere(): void
    {
        $examples = (new PatternFinder)->find(self::articles(), self::entries())->examples;

        $this->assertCount(2, $examples);
        $this->assertSame('Orchards', $examples[0]['title']);
        $this->assertSame([
            ['type' => 'hero', 'heading' => 'Orchards'],
            ['type' => 'text', 'copy' => 'Words about Orchards.'],
            ['type' => 'quote'],
            ['type' => 'spacer'],
        ], $examples[0]['body']);
    }

    public function test_house_defaults_leave_out_bookkeeping_written_fields_and_empty_values(): void
    {
        $entries = array_map(fn (EntryData $entry) => new EntryData($entry->values + ['image' => [7], 'slug' => 'same', 'date' => '2026-01-01', 'notes' => ''], $entry->id), self::entries());

        $craft = (new PatternFinder)->find(self::articles(), $entries);
        $statamic = (new PatternFinder(LayoutOptions::statamic()))->find(self::articles(), $entries);

        // Craft's entries have no `date` of their own, so a shared one is a default;
        // to Statamic it is bookkeeping. An empty value is a field nobody uses.
        $this->assertSame(['image' => [7], 'date' => '2026-01-01'], $craft->fixed);
        $this->assertSame(['image' => [7]], $statamic->fixed);
    }

    public function test_content_pasted_in_a_few_versions_is_still_copied(): void
    {
        $entries = [];

        foreach (['A', 'A', 'B', 'B'] as $i => $version) {
            $entries[] = new EntryData(['title' => "Page {$i}", 'body' => [['id' => $i, 'type' => 'quote', 'quote' => "Version {$version}", 'person' => "Person {$version}"]]], $i);
        }

        $body = (new PatternFinder)->find(self::articles(), $entries)->blocks['body'];

        $this->assertSame([], $body['fixed']);
        $this->assertSame([], $body['boilerplate']);

        // Structured content reused in versions counts, as no block has its own.
        $schema = self::articles();
        $rows = [];

        foreach (['A', 'A', 'B', 'B'] as $i => $version) {
            $rows[] = new EntryData(['title' => "Page {$i}", 'body' => [['type' => 'gallery', 'images' => [['id' => $i, 'src' => $version]]]]], $i);
        }

        $this->assertSame(['images' => [['id' => 0, 'src' => 'A']]], (new PatternFinder)->find($schema, $rows)->blocks['body']['fixed']['gallery']);
    }

    public function test_blocks_switched_off_are_not_part_of_the_pattern(): void
    {
        $entries = self::entries();
        $values = $entries[0]->values;
        $values['body'][] = ['type' => 'gallery', 'enabled' => false, 'images' => [1]];
        $entries[0] = new EntryData($values, 3);

        $body = (new PatternFinder)->find(self::articles(), $entries)->blocks['body'];

        $this->assertArrayNotHasKey('gallery', $body['usage']);
        $this->assertSame(['hero', 'text', 'quote', 'spacer'], $body['sequence']);
    }

    public function test_a_tie_between_orders_goes_to_the_newest(): void
    {
        $entries = [
            new EntryData(['title' => 'New', 'body' => [['type' => 'text'], ['type' => 'hero']]], 2),
            new EntryData(['title' => 'Old', 'body' => [['type' => 'hero'], ['type' => 'text']]], 1),
        ];

        $this->assertSame(['text', 'hero'], (new PatternFinder)->find(self::articles(), $entries)->blocks['body']['sequence']);
    }

    public function test_nothing_published_gives_an_empty_pattern(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), []);

        $this->assertSame(0, $pattern->entries);
        $this->assertSame(0, $pattern->words);
        $this->assertSame(['sequence' => [], 'usage' => [], 'fixed' => [], 'used' => [], 'boilerplate' => []], $pattern->blocks['body']);
        $this->assertTrue($pattern->house->isEmpty());
    }

    public function test_the_entries_to_learn_from_are_chosen_as_the_addons_chose_them(): void
    {
        $entries = [];

        for ($i = 40; $i > 0; $i--) {
            $entries[] = new EntryData(['title' => "Entry {$i}", 'tags' => $i % 2 ? ['guide'] : ['news'], 'tone' => $i % 5 ? 'warm' : 'dry'], $i);
        }

        $this->assertCount(PatternFinder::SAMPLE, PatternFinder::choose($entries));
        $this->assertSame(40, PatternFinder::choose($entries)[0]->id);

        $guides = PatternFinder::choose($entries, ['tags' => 'guide']);
        $this->assertCount(20, $guides);
        $this->assertSame(39, $guides[0]->id);

        $this->assertSame([40, 35, 30, 25, 20, 15, 10, 5], array_map(fn (EntryData $entry) => $entry->id, PatternFinder::choose($entries, ['tone' => 'dry'])));

        // Until some match, every entry is the evidence.
        $this->assertCount(PatternFinder::SAMPLE, PatternFinder::choose($entries, ['tags' => 'recipe']));
    }

    public function test_a_pattern_round_trips_through_the_addons_array(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), self::entries());

        $this->assertEquals($pattern, Pattern::fromArray($pattern->toArray()));
        $this->assertSame(['entries', 'words', 'blocks', 'fixed', 'examples', 'filled', 'house'], array_keys($pattern->toArray()));
        $this->assertEquals(new Pattern, Pattern::fromArray([]));
    }
}
