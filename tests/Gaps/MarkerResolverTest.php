<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use PHPUnit\Framework\TestCase;

class MarkerResolverTest extends TestCase
{
    public function test_an_answer_replaces_a_fact_to_add_exactly_as_typed(): void
    {
        $text = 'Adults pay [[ask: adult ticket price]] and children [[ask: child ticket price]].';

        $this->assertSame('Adults pay £12.50 *on the door* and children [[ask: child ticket price]].', Markers::resolveAsk($text, '[[ask: adult ticket price]]', "£12.50 *on the door*\r\n"));
        $this->assertSame('Adults pay and children [[ask: child ticket price]].', Markers::resolveAsk($text, '[[ask: adult ticket price]]', ''));
        $this->assertSame($text, Markers::resolveAsk($text, '[[ask: nothing like it]]', 'x'));
        $this->assertSame('A then [[ask: x]]', Markers::resolveAsk('[[ask: x]] then [[ask: x]]', '[[ask: x]]', 'A'));
        $this->assertSame('[[ask: x]] then B', Markers::resolveAsk('[[ask: x]] then [[ask: x]]', '[[ask: x]]', 'B', 1));
    }

    public function test_a_link_to_choose_is_pointed_at_an_address_its_words_kept(): void
    {
        $text = 'Book a visit or [talk to us](#gw-link:contact-page) today.';
        $match = Markers::links($text)[0]['match'];

        $this->assertSame('Book a visit or [talk to us](/contact) today.', Markers::resolveLink($text, $match, '/contact'));
        $this->assertSame('Book a visit or [talk to us](statamic://entry::a%20b) today.', Markers::resolveLink($text, $match, 'statamic://entry::a b'));
        $this->assertSame('Book a visit or talk to us today.', Markers::resolveLink($text, $match, ''));
        $this->assertSame($text, Markers::resolveLink($text, 'not a link', '/x'));
    }

    public function test_leaves_lists_every_string_with_its_path(): void
    {
        $this->assertSame([
            ['path' => ['title'], 'text' => 'Hi'],
            ['path' => ['page_builder', 0, 'text'], 'text' => 'Body'],
        ], MarkerResolver::leaves(['title' => 'Hi', 'count' => 3, 'page_builder' => [['type' => null, 'text' => 'Body']]]));
    }

    public function test_a_chip_finds_its_marker_by_kind_hint_and_occurrence(): void
    {
        $texts = MarkerResolver::leaves([
            'title' => 'Open days',
            'intro' => 'From [[ask: adult ticket price]]; [[ask: Adult  ticket price]] for groups.',
            'body' => 'Again [[ask: adult ticket price]]. We cover [[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]].',
            'cta' => ['text' => 'Contact', 'link' => 'https://example.com/#gw-link:contact-page'],
            'more' => 'Or [write to us](#gw-link:contact_page).',
        ]);

        $first = MarkerResolver::find($texts, 'ask', 'adult ticket price');
        $this->assertSame(['intro'], $first['path']);
        $this->assertSame(0, $first['occurrence']);

        $second = MarkerResolver::find($texts, 'ask', 'adult ticket price', occurrence: 1);
        $this->assertSame(['intro'], $second['path']);
        $this->assertSame('[[ask: Adult  ticket price]]', $second['match']);
        $this->assertSame('From [[ask: adult ticket price]]; £12 for groups.', MarkerResolver::apply($second['text'], $second, '£12'));

        $third = MarkerResolver::find($texts, 'ask', 'adult ticket price', occurrence: 2);
        $this->assertSame(['body'], $third['path']);
        $this->assertSame('Again £12. We cover [[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]].', MarkerResolver::apply($third['text'], $third, '£12'));

        // More than there are: the last.
        $this->assertSame(['body'], MarkerResolver::find($texts, 'ask', 'adult ticket price', occurrence: 9)['path']);
        $this->assertNull(MarkerResolver::find($texts, 'ask', 'nothing like it'));
        $this->assertNull(MarkerResolver::find($texts, 'something', 'adult ticket price'));
    }

    public function test_a_count_to_check_is_confirmed_changed_or_removed(): void
    {
        $texts = MarkerResolver::leaves(['body' => 'We cover [[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]] today.']);
        $found = MarkerResolver::find($texts, 'check', '3 areas', 'Northumberland, Durham and the Tyne Valley');

        $this->assertSame('We cover 3 areas today.', MarkerResolver::apply($found['text'], $found, '3 areas'));
        $this->assertSame('We cover four areas today.', MarkerResolver::apply($found['text'], $found, ' four areas '));
        $this->assertSame('We cover today.', MarkerResolver::apply($found['text'], $found, ''));

        // A list that no longer matches still finds the count by its value.
        $this->assertNotNull(MarkerResolver::find($texts, 'check', '3 areas', 'Somewhere else'));
    }

    public function test_a_count_s_list_tells_two_of_the_same_value_apart(): void
    {
        $texts = MarkerResolver::leaves([
            'a' => '[[check: 3 areas | from: A, B and C]]',
            'b' => '[[check: 3 areas | from: D, E and F]]',
        ]);

        $this->assertSame(['b'], MarkerResolver::find($texts, 'check', '3 areas', 'D, E and F')['path']);
    }

    public function test_links_are_found_in_markdown_and_as_a_whole_value(): void
    {
        $texts = MarkerResolver::leaves([
            'cta' => ['link' => 'https://example.com/#gw-link:contact-page'],
            'more' => 'Or [write to us](#gw-link:contact_page).',
        ]);

        // A chip shows a link's hint with spaces for hyphens.
        $field = MarkerResolver::find($texts, 'link', 'contact page');
        $this->assertSame(['cta', 'link'], $field['path']);
        $this->assertTrue($field['whole']);
        $this->assertSame('entry::abc', MarkerResolver::apply($field['text'], $field, 'entry::abc'));

        $inline = MarkerResolver::find($texts, 'link', 'contact page', occurrence: 1);
        $this->assertSame(['more'], $inline['path']);
        $this->assertFalse($inline['whole']);
        $this->assertSame('Or [write to us](/contact).', MarkerResolver::apply($inline['text'], $inline, '/contact'));
    }

    public function test_a_link_for_a_field_the_draft_doesnt_hold_is_chosen_by_hint_and_put_in_where_built(): void
    {
        $data = MarkerResolver::chooseLink(['title' => 'Winter'], 'Button  link', 'entry::abc', '/about');
        $data = MarkerResolver::chooseLink($data, 'contact-page', 'entry::def', '/contact');

        $this->assertSame(['button link' => ['link' => 'entry::abc', 'url' => '/about'], 'contact page' => ['link' => 'entry::def', 'url' => '/contact']], $data[MarkerResolver::CHOSEN_LINKS]);

        $chosen = MarkerResolver::chosenLinks($data);
        $built = [
            'hero' => ['button_link' => '#gw-link:button-link', 'other' => '#gw-link:something-else'],
            'craft' => ['type' => 'url', 'value' => 'https://example.com/#gw-link:button-link'],
            'bard' => [['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#gw-link:contact-page']]]]],
            'html' => '<p><a href="https://example.com/#gw-link:contact-page">Us</a> and [x](#gw-link:nope)</p>',
        ];

        $this->assertSame([
            'hero' => ['button_link' => 'entry::abc', 'other' => '#gw-link:something-else'],
            'craft' => ['type' => 'url', 'value' => 'entry::abc'],
            'bard' => [['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/contact']]]]],
            'html' => '<p><a href="/contact">Us</a> and [x](#gw-link:nope)</p>',
        ], MarkerResolver::withChosenLinks($built, $chosen));

        // Addresses only (Craft's link fields take a URL).
        $this->assertSame('/about', MarkerResolver::withChosenLinks($built, $chosen, references: false)['craft']['value']);
        $this->assertSame($built, MarkerResolver::withChosenLinks($built, []));
        $this->assertSame([], MarkerResolver::chosenLinks(['gw_links' => 'nonsense']));
    }
}
