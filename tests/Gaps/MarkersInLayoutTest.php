<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use PHPUnit\Framework\TestCase;

/**
 * Markers through the layout code: the builder keeps them in text, lists
 * the ones meant for fields that can't hold text, and the house style can
 * mark a link still to choose with the sentinel.
 */
class MarkersInLayoutTest extends TestCase
{
    public function test_markers_in_text_are_kept_and_near_misses_put_right(): void
    {
        $schema = new Schema([
            new Field('title', Kind::Text),
            new Field('intro', Kind::LongText),
            new Field('body', Kind::RichText),
            new Field('tags', Kind::List),
        ]);

        $built = (new EntryBuilder)->build([
            'title' => 'Opening times',
            'intro' => 'Tickets cost [ask: adult price].',
            'body' => "We open at [[ASK:opening time]]. [Talk to us](#gw-link:contact-page).\n\n<b>no</b>",
            'tags' => ['[[ask - a tag]]'],
        ], $schema);

        $this->assertSame('Tickets cost [[ask: adult price]].', $built->data['intro']);
        $this->assertSame("<p>We open at [[ask: opening time]]. <a href=\"#gw-link:contact-page\">Talk to us</a>.</p>\n<p>&lt;b&gt;no&lt;/b&gt;</p>", $built->data['body']);
        $this->assertSame(['[[ask: a tag]]'], $built->data['tags']);
        $this->assertSame([], $built->asks);
        $this->assertSame([], $built->notes);
    }

    public function test_a_fact_meant_for_a_field_that_cant_hold_text_is_listed_with_where_it_goes(): void
    {
        $ids = 0;
        $schema = new Schema([
            new Field('price', Kind::Number, 'Price'),
            new Field('size', Kind::Choice, 'Size', options: ['s' => 'Small']),
            new Field('open', Kind::Toggle),
            new Field('date', Kind::Reference, 'Event date'),
            new Field('body', Kind::Blocks, sets: ['offer' => new Set('Offer', '', [
                new Field('heading', Kind::Text),
                new Field('amount', Kind::Number, 'Amount'),
                new Field('picture', Kind::Reference, 'Picture', files: true),
            ])]),
        ]);
        $pattern = new Pattern(blocks: ['body' => ['sequence' => [], 'usage' => [], 'fixed' => [], 'used' => ['offer' => ['picture']], 'boilerplate' => []]]);

        $built = (new EntryBuilder(LayoutOptions::statamic(function () use (&$ids) {
            return 'b'.++$ids;
        })))->build([
            'price' => '[[ask: adult ticket price]]',
            'size' => '[[ask: size]]',
            'open' => '[[ask: open on Sundays?]]',
            'date' => 'On [[ask: event date]]',
            'body' => [['type' => 'offer', 'heading' => 'Save', 'amount' => '[ask: discount]'], ['type' => 'offer', 'amount' => 12]],
        ], $schema, $pattern);

        $this->assertSame(['body' => [
            ['id' => 'b1', 'type' => 'offer', 'enabled' => true, 'heading' => 'Save'],
            ['id' => 'b2', 'type' => 'offer', 'enabled' => true, 'amount' => 12],
        ]], $built->data);
        $this->assertSame([
            ['path' => 'price', 'label' => 'Price', 'hint' => 'adult ticket price'],
            ['path' => 'size', 'label' => 'Size', 'hint' => 'size'],
            ['path' => 'open', 'label' => 'open', 'hint' => 'open on Sundays?'],
            ['path' => 'date', 'label' => 'Event date', 'hint' => 'event date'],
            ['path' => 'body/#b1/amount', 'label' => 'Offer: Amount', 'hint' => 'discount'],
        ], $built->asks);
        $this->assertSame([
            ['path' => 'body/#b1/picture', 'label' => 'Offer: Picture'],
            ['path' => 'body/#b2/picture', 'label' => 'Offer: Picture'],
        ], $built->toFill);
        $this->assertSame([
            'Still to choose by hand: Offer: Picture.',
            'Still to add by hand: Price (adult ticket price); Size (size); open (open on Sundays?); Event date (event date); Offer: Amount (discount).',
        ], $built->notes);
    }

    public function test_without_block_ids_places_are_named_by_position(): void
    {
        $schema = new Schema([new Field('body', Kind::Blocks, sets: ['offer' => new Set('Offer', '', [new Field('amount', Kind::Number, 'Amount')])])]);

        $built = (new EntryBuilder)->build(['body' => [['type' => 'offer', 'amount' => 3], ['type' => 'offer', 'amount' => '[[ask: discount]]']]], $schema);

        $this->assertSame([['path' => 'body/1/amount', 'label' => 'Offer: Amount', 'hint' => 'discount']], $built->asks);
    }

    public function test_the_house_style_marks_a_link_to_choose_with_the_sentinel_when_asked(): void
    {
        $schema = new Schema([new Field('body', Kind::Blocks, sets: ['cta' => new Set('Call to action', '', [
            new Field('heading', Kind::Text),
            new Field('button_link', Kind::Reference, 'Button link', required: true, type: 'link'),
            new Field('button_text', Kind::Text),
        ])])]);
        $data = ['body' => [['type' => 'cta', 'heading' => 'Talk to us']]];

        $statamic = new HouseStyle(LayoutOptions::statamic(fn () => 'x')->withLinkSentinels(), new BardDialect, new StatamicLinks);
        $result = $statamic->apply($data, $schema, new HouseRules);

        $this->assertSame(['type' => 'cta', 'heading' => 'Talk to us', 'button_link' => '#gw-link:button-link', 'button_text' => LinkDialect::PLACEHOLDER_TEXT], $result->data['body'][0]);
        $this->assertSame(['Call to action (link still to choose)'], $result->toFill);

        // Off, as in 1.x by default: example.com, as before.
        $before = (new HouseStyle(LayoutOptions::statamic(fn () => 'x'), new BardDialect, new StatamicLinks))->apply($data, $schema, new HouseRules);
        $this->assertSame(LinkDialect::PLACEHOLDER_URL, $before->data['body'][0]['button_link']);
        $this->assertSame(['Call to action (links to example.com for now)'], $before->toFill);

        $hyper = 'verbb\\hyper\\fields\\HyperField';
        $craftSchema = new Schema([new Field('body', Kind::Blocks, sets: ['cta' => new Set('Call to action', '', [
            new Field('link', Kind::Reference, 'Button link', required: true, type: $hyper),
        ])])]);
        $craft = (new HouseStyle((new LayoutOptions)->withLinkSentinels(), new HtmlDialect, new CraftLinks(hyper: [$hyper])))->apply(['body' => [['type' => 'cta']]], $craftSchema, new HouseRules);

        $this->assertSame([['type' => CraftLinks::HYPER_URL, 'linkValue' => 'https://example.com/#gw-link:button-link', 'linkText' => 'Link to choose']], $craft->data['body'][0]['link']);
    }

    public function test_which_text_fields_can_hold_a_link_mark(): void
    {
        foreach ([new StatamicLinks, new CraftLinks, new NoLinks] as $links) {
            $this->assertTrue($links->supportsLinks(new Field('body', Kind::RichText)));
            $this->assertFalse($links->supportsLinks(new Field('body', Kind::RichText, meta: ['buttons' => ['bold', 'italic']])));
            $this->assertTrue($links->supportsLinks(new Field('body', Kind::RichText, meta: ['buttons' => ['bold', 'anchor']])));
            $this->assertTrue($links->supportsLinks(new Field('notes', Kind::LongText, type: 'markdown')));
            $this->assertTrue($links->supportsLinks(new Field('notes', Kind::LongText, meta: ['format' => 'markdown'])));
            $this->assertFalse($links->supportsLinks(new Field('intro', Kind::LongText)));
            $this->assertFalse($links->supportsLinks(new Field('title', Kind::Text)));
        }

        $this->assertNull((new NoLinks)->placeholderFor(new Field('url', Kind::Text), [], 'x'));
        $this->assertSame(['link' => ['type' => 'url', 'value' => 'https://example.com/#gw-link:more', 'label' => 'Link to choose']], (new CraftLinks(link: ['craft\\fields\\Link']))->placeholderFor(new Field('link', Kind::Reference, type: 'craft\\fields\\Link'), [], 'More'));
    }
}
