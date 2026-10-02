<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use PHPUnit\Framework\TestCase;

class MarkersTest extends TestCase
{
    public function test_a_fact_to_add_is_written_strictly(): void
    {
        $this->assertSame('[[ask: adult ticket price]]', Markers::ask('  adult   ticket price '));
        $this->assertSame('[[ask: price of a ticket]]', Markers::ask("price [of]\n a ticket"));
        $this->assertSame('[[ask: something to add]]', Markers::ask('[]'));
        $this->assertLessThanOrEqual(Markers::MAX_HINT + 9, mb_strlen(Markers::ask(str_repeat('word ', 60))));
    }

    public function test_facts_to_add_are_found_leniently_with_their_occurrence(): void
    {
        $found = Markers::asks('Tickets cost [[ask: adult price]] or [[ ASK:Adult  price ]] and [[Ask : child price]].');

        $this->assertSame(['adult price', 'Adult  price', 'child price'], array_column($found, 'hint'));
        $this->assertSame([0, 1, 0], array_column($found, 'occurrence'));
        $this->assertSame(13, $found[0]['offset']);
        $this->assertSame('[[ ASK:Adult  price ]]', $found[1]['match']);

        // Not facts to add: a vocabulary placeholder, a single bracket, an empty one.
        $this->assertSame([], Markers::asks('[[item]] [ask: x] [[ask: ]] [[ask:a]b]]'));
    }

    public function test_links_to_choose_are_found_inline_and_in_link_fields(): void
    {
        $found = Markers::links('[Talk to us](#gw-link:contact-page) or [book](https://example.com/#gw-link:booking "Book") and [a real one](/contact).');

        $this->assertSame(['contact-page', 'booking'], array_column($found, 'hint'));
        $this->assertSame(['Talk to us', 'book'], array_column($found, 'words'));

        $this->assertSame('#gw-link:contact-page', Markers::link('Contact page'));
        $this->assertSame('https://example.com/#gw-link:button-link', Markers::linkUrl('Button link'));
        $this->assertSame('#gw-link:link', Markers::link('***'));
        $this->assertTrue(Markers::isLinkSentinel('https://example.com/#gw-link:x'));
        $this->assertFalse(Markers::isLinkSentinel('https://example.com'));
        $this->assertFalse(Markers::isLinkSentinel(['#gw-link:x']));
        $this->assertSame('contact page', Markers::linkHint('#gw-link:contact%20page'));
        $this->assertNull(Markers::linkHint('/contact'));
    }

    public function test_leftover_vocabulary_and_placeholder_looking_text_are_found_apart_from_asks(): void
    {
        $text = 'Some [[item]] text, [[ask: a price]], TBC, tbc, [insert date], [Add to basket](/basket), [...], ??? and Lorem Ipsum. TODO: XXXX';

        $this->assertSame(['item'], array_column(Markers::leftovers($text), 'hint'));
        $this->assertSame(['TBC', '[insert date]', '[...]', '???', 'Lorem Ipsum', 'TODO', 'XXXX'], array_column(Markers::placeholderText($text), 'hint'));
        $this->assertSame([], Markers::placeholderText('The Address of the TBCX company? Yes [added](x).'));
    }

    public function test_near_misses_are_put_right(): void
    {
        $this->assertSame(
            'Cost [[ask: adult price]], [[ask: child price]], [[ask: date]] and [link](#gw-link:x) [a](b)',
            Markers::normalise('Cost [ask: adult price], [[Ask - child price]], [[ ASK:date ]] and [link](#gw-link:x) [a](b)'),
        );
        $this->assertSame('Nothing to ask here.', Markers::normalise('Nothing to ask here.'));
    }

    public function test_the_excerpt_is_the_sentence_around_the_marker(): void
    {
        $text = "## Prices\n\nWe open at nine. Tickets cost [[ask: adult price]] for adults! Children go free.";
        [$found] = Markers::asks($text);

        $this->assertSame('Tickets cost [[ask: adult price]] for adults!', Markers::excerpt($text, $found['offset'], strlen($found['match'])));

        $list = "- One\n- Two costs [[ask: price]]\n- Three";
        [$found] = Markers::asks($list);
        $this->assertSame('Two costs [[ask: price]]', Markers::excerpt($list, $found['offset'], strlen($found['match'])));
    }

    public function test_a_slug_leaves_out_facts_to_add(): void
    {
        $this->assertSame('tickets-from-for-adults', Slug::make('Tickets from [[ask: price]] for adults'));
    }

    public function test_field_paths_name_blocks_by_id_where_they_have_one(): void
    {
        $path = new FieldPath(['page_builder', new BlockRef('a1b2', 1, 'hero'), 'intro']);

        $this->assertSame('page_builder/#a1b2/intro', $path->toString());
        $this->assertSame('page_builder.1.intro', $path->dotted());
        $this->assertSame('page_builder', $path->handle());
        $this->assertSame('intro', $path->field());
        $this->assertSame('rows/2/q', FieldPath::of('rows')->with(new BlockRef(null, 2))->with('q')->toString());
        $this->assertTrue(FieldPath::parse('page_builder/#a1b2/intro')->equals($path));
        $this->assertSame('rows/2/q', FieldPath::parse('/rows/2/q/')->toString());
    }

    public function test_the_ask_and_link_names_are_reserved_from_the_vocabulary(): void
    {
        $names = array_keys(Vocabulary::PHRASES);

        foreach ([Vocabulary::statamic(), Vocabulary::craft(), Vocabulary::filament()] as $vocabulary) {
            foreach (array_keys($vocabulary->terms()) as $term) {
                $names[] = trim($term, '[]');
            }
        }

        foreach ($names as $name) {
            $this->assertNotContains(strtolower($name), [Markers::ASK, 'link', Markers::LINK], "\"{$name}\" is reserved for Ghostwriter's markers.");
            $this->assertStringNotContainsString(':', $name, 'A vocabulary placeholder never has a colon, so it can never read as a marker.');
            $this->assertSame([], Markers::asks("[[{$name}]]"));
        }
    }

    public function test_the_writer_is_shown_the_markers_and_no_vocabulary_is_left_in_any_prompt(): void
    {
        $writer = (new PromptLibrary(Vocabulary::statamic()))->get('writer');

        $this->assertStringContainsString('[[ask: adult ticket price]]', $writer);
        $this->assertStringContainsString('(#gw-link:contact-page)', $writer);

        foreach (PromptLibrary::NAMES as $name) {
            foreach ([Vocabulary::statamic(), Vocabulary::craft(), Vocabulary::filament()] as $vocabulary) {
                $this->assertSame([], Markers::leftovers((new PromptLibrary($vocabulary))->get($name)), "{$name} has a vocabulary placeholder left in it.");
            }
        }
    }

    public function test_the_front_end_patterns_file_is_up_to_date(): void
    {
        $json = json_encode(Markers::patterns(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            file_put_contents(Markers::patternsFile(), $json);
        }

        $this->assertSame($json, file_get_contents(Markers::patternsFile()), 'Run the tests with GHOSTWRITER_UPDATE_FIXTURES=1 to write resources/gaps/patterns.json.');
    }

    public function test_the_front_end_patterns_compile_and_match_in_javascript(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->markTestSkipped('Node is not installed.');
        }

        $script = <<<'JS'
            const p = require(process.argv[1]);
            const text = "Cost [[ ASK:adult price ]] and [Talk](#gw-link:contact-page) TBC [insert date] [[item]]";
            const all = (r, g) => [...text.matchAll(new RegExp(r.source, r.flags))].map(m => m[g]);
            console.log(JSON.stringify([all(p.ask, 1), all(p.link, 2), all(p.leftover, 1), Object.values(p.placeholderText).flatMap(r => all(r, 0))]));
            JS;

        $out = shell_exec(escapeshellarg($node).' -e '.escapeshellarg($script).' '.escapeshellarg(Markers::patternsFile()));
        $text = 'Cost [[ ASK:adult price ]] and [Talk](#gw-link:contact-page) TBC [insert date] [[item]]';

        $this->assertSame([
            array_column(Markers::asks($text), 'hint'),
            array_column(Markers::links($text), 'hint'),
            array_column(Markers::leftovers($text), 'hint'),
            ['TBC', '[insert date]'],
        ], json_decode((string) $out, true));
    }
}
