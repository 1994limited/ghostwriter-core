<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseResult;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;

/**
 * What model pages agree on, place by place, carried into a new one. The
 * Craft cases are a studio landing page built with Neo, as on a real site;
 * the Statamic ones a replicator with Bard and link fields.
 */
class HouseStyleTest extends LayoutTestCase
{
    private const HERO = '<h1 style="text-align:center;"><span style="color:hsl(0,0%,100%);"><span class="style-uppercase">TITLE</span></span></h1>';

    private static function craft(): HouseStyle
    {
        return new HouseStyle(LayoutOptions::craft(), new HtmlDialect, new CraftLinks(hyper: [Addons::HYPER], link: [Addons::LINK]));
    }

    private static function craftSchema(): Schema
    {
        $text = ['text' => new Set('Text', '', [new Field('richText', Kind::RichText, 'Rich text', type: 'craft\\ckeditor\\Field')])];
        $children = new Field('children', Kind::Blocks, 'Blocks inside', type: 'neo-children', engine: Field::CHILDREN, sets: $text);

        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true, type: 'title'),
            new Field('pageBuilder', Kind::Blocks, 'Page builder', type: 'benf\\neo\\Field', engine: 'neo', sets: [
                'hero' => new Set('Hero', '', [$children]),
                'breadcrumbs' => new Set('Breadcrumbs', '', [
                    new Field('crumbs', Kind::Reference, 'Crumbs', type: 'craft\\fields\\Matrix', engine: 'matrix', sets: [
                        'crumb' => new Set('Crumb', '', [new Field('link', Kind::Reference, 'Link', type: Addons::HYPER)]),
                    ]),
                ]),
                'spacer' => new Set('Spacer', '', [new Field('mobile', Kind::Number, 'Mobile'), new Field('desktop', Kind::Number, 'Desktop')]),
                'body' => new Set('Body', '', [$children]),
            ]),
        ]);
    }

    private static function craftPage(string $title, int $id): EntryData
    {
        $link = fn (string $text, int $to) => [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => [$to], 'linkText' => $text]];

        return new EntryData(['title' => $title, 'pageBuilder' => [
            ['id' => $id * 10 + 1, 'type' => 'hero', 'enabled' => true, 'children' => [['id' => $id * 10 + 2, 'type' => 'text', 'enabled' => true, 'richText' => str_replace('TITLE', $title, self::HERO)]]],
            ['id' => $id * 10 + 3, 'type' => 'breadcrumbs', 'enabled' => true, 'crumbs' => [
                ['id' => 1, 'type' => 'crumb', 'enabled' => true, 'link' => $link('Home', 10)],
                ['id' => 2, 'type' => 'crumb', 'enabled' => true, 'link' => $link('Studio', 1166)],
                ['id' => 3, 'type' => 'crumb', 'enabled' => true, 'link' => $link($title, $id)],
            ]],
            ['id' => $id * 10 + 4, 'type' => 'spacer', 'enabled' => true, 'mobile' => 45, 'desktop' => 65],
            ['id' => $id * 10 + 5, 'type' => 'body', 'enabled' => true, 'children' => [['id' => $id * 10 + 6, 'type' => 'text', 'enabled' => true, 'richText' => "<p>About {$title}.</p>"]]],
            ['id' => $id * 10 + 7, 'type' => 'spacer', 'enabled' => true, 'mobile' => 50, 'desktop' => 100],
        ]], $id);
    }

    public function test_a_page_is_filled_in_from_what_its_models_agree_on(): void
    {
        // Without the pages' IDs, a link to the page itself can't be told.
        $pages = array_map(fn (EntryData $page) => new EntryData($page->values), [self::craftPage('Yacht Studio', 1504), self::craftPage('Architecture Studio', 9330), self::craftPage('Aviation Studio', 9294)]);
        $style = self::craft()->learn($pages, self::craftSchema());

        $result = self::craft()->apply(['title' => 'Studio Winch', 'pageBuilder' => [
            ['type' => 'hero', 'enabled' => true, 'children' => [['type' => 'text', 'enabled' => true, 'richText' => '<h1>Studio Winch</h1>']]],
            ['type' => 'breadcrumbs', 'enabled' => true],
            ['type' => 'spacer', 'enabled' => true],
            ['type' => 'body', 'enabled' => true, 'children' => [['type' => 'text', 'enabled' => true, 'richText' => '<p>Words.</p>']]],
            ['type' => 'spacer', 'enabled' => true],
        ]], self::craftSchema(), $style);

        [$hero, $crumbs, $first, $body, $second] = $result->data['pageBuilder'];

        $this->assertSame([45, 65], [$first['mobile'], $first['desktop']]);
        $this->assertSame([50, 100], [$second['mobile'], $second['desktop']]);
        $this->assertCount(3, $crumbs['crumbs']);
        $this->assertSame('Home', $crumbs['crumbs'][0]['link'][0]['linkText']);
        $this->assertSame('Studio', $crumbs['crumbs'][1]['link'][0]['linkText']);
        $this->assertSame([['type' => CraftLinks::HYPER_URL, 'linkValue' => LinkDialect::PLACEHOLDER_URL, 'linkText' => LinkDialect::PLACEHOLDER_TEXT]], $crumbs['crumbs'][2]['link']);
        $this->assertSame(['Breadcrumbs: Crumb 3 (links to example.com for now)'], $result->toFill);
        $this->assertSame('Still to set by hand, as it differs from page to page: Breadcrumbs: Crumb 3 (links to example.com for now).', $result->note());

        // The hero heading is dressed as the house dresses it; body text is left plain.
        $this->assertSame(str_replace('TITLE', 'Studio Winch', self::HERO), $hero['children'][0]['richText']);
        $this->assertSame('<p>Words.</p>', $body['children'][0]['richText']);
    }

    public function test_a_link_to_the_page_itself_becomes_a_link_to_the_new_page_whatever_each_calls_it(): void
    {
        $pages = [self::craftPage('Yacht Studio', 1504), self::craftPage('Architecture Studio', 9330), self::craftPage('Visualisation Studio', 3111), self::craftPage('Procurement Team', 2642)];
        $values = [$pages[1]->values, $pages[2]->values, $pages[3]->values];
        $values[0]['pageBuilder'][1]['crumbs'][2]['link'][0]['linkText'] = 'Architecture';
        unset($values[1]['pageBuilder'][1]['crumbs'][2]['link'], $values[2]['pageBuilder'][1]['crumbs'][2]['link']);
        $pages = [$pages[0], new EntryData($values[0], 9330), new EntryData($values[1], 3111), new EntryData($values[2], 2642)];

        $style = self::craft()->learn($pages, self::craftSchema());
        $this->assertSame(LinkDialect::SELF, $style->positions['pageBuilder/breadcrumbs#0/crumbs/crumb#2']['link'][0]['linkValue']);

        $last = self::craft()->apply(['pageBuilder' => [['type' => 'breadcrumbs', 'enabled' => true]]], self::craftSchema(), $style, 50724, 'Studio Winch')->data['pageBuilder'][0]['crumbs'][2]['link'][0];

        $this->assertSame([50724], $last['linkValue']);
        $this->assertSame('Studio Winch', $last['linkText']);
    }

    public function test_a_place_the_models_disagree_on_is_left_alone(): void
    {
        $pages = [self::craftPage('One', 1), self::craftPage('Two', 2)];
        $values = $pages[1]->values;
        $values['pageBuilder'][2]['mobile'] = 10;
        $values['pageBuilder'][0]['children'][0]['richText'] = '<h1>Plain</h1>';
        $pages[1] = new EntryData($values, 2);

        $style = self::craft()->learn($pages, self::craftSchema());
        $data = self::craft()->apply(['pageBuilder' => [
            ['type' => 'hero', 'enabled' => true, 'children' => [['type' => 'text', 'richText' => '<h1>New</h1>']]],
            ['type' => 'breadcrumbs', 'enabled' => true],
            ['type' => 'spacer', 'enabled' => true],
        ]], self::craftSchema(), $style)->data;

        $this->assertArrayNotHasKey('mobile', $data['pageBuilder'][2]);
        $this->assertSame(65, $data['pageBuilder'][2]['desktop']);
        $this->assertSame('<h1>New</h1>', $data['pageBuilder'][0]['children'][0]['richText']);
    }

    private static function statamic(): HouseStyle
    {
        $ids = 0;

        return new HouseStyle(LayoutOptions::statamic(function () use (&$ids): string {
            return 'new'.++$ids;
        }), new BardDialect, new StatamicLinks);
    }

    private static function statamicSchema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', type: 'text'),
            new Field('page_builder', Kind::Blocks, type: 'replicator', sets: [
                'spacer' => new Set('Spacer', '', [new Field('height', Kind::Text, type: 'text')]),
                'hero' => new Set('Hero', '', [
                    new Field('heading', Kind::RichText, type: 'bard'),
                    new Field('image', Kind::Reference, 'Image', type: 'assets', files: true),
                    new Field('button_text', Kind::Text, type: 'text'),
                    new Field('button_link', Kind::Reference, 'Button link', type: 'link'),
                    new Field('related', Kind::Reference, 'Related', type: 'entries'),
                ]),
                'breadcrumbs' => new Set('Breadcrumbs', '', [
                    new Field('crumbs', Kind::Blocks, type: 'replicator', sets: [
                        'crumb' => new Set('Crumb', '', [new Field('label', Kind::Text, type: 'text'), new Field('link', Kind::Reference, type: 'link')]),
                    ]),
                ]),
            ]),
        ]);
    }

    private static function statamicPage(string $id, string $title): EntryData
    {
        $heading = [['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $title, 'marks' => [['type' => 'bold']]]]]];

        return new EntryData(['title' => $title, 'page_builder' => [
            ['id' => 's1', 'type' => 'spacer', 'enabled' => true, 'height' => '45/65'],
            ['id' => 'h1', 'type' => 'hero', 'enabled' => true, 'heading' => $heading, 'button_text' => 'Talk to us', 'button_link' => 'entry::contact', 'related' => ["{$id}-related"]],
            ['id' => 'b1', 'type' => 'breadcrumbs', 'enabled' => true, 'crumbs' => [
                ['id' => 'c1', 'type' => 'crumb', 'enabled' => true, 'label' => 'Home', 'link' => 'entry::home'],
                ['id' => 'c2', 'type' => 'crumb', 'enabled' => true, 'label' => 'Studio', 'link' => 'entry::studio'],
                ['id' => 'c3', 'type' => 'crumb', 'enabled' => true, 'label' => $title, 'link' => "entry::{$id}"],
            ]],
        ]], $id);
    }

    public function test_statamic_learns_bard_dressing_and_links_to_itself_once_the_entry_exists(): void
    {
        $pages = [self::statamicPage('p1', 'Yacht Studio'), self::statamicPage('p2', 'Architecture Studio'), self::statamicPage('p3', 'Visualisation Studio')];
        $style = self::statamic()->learn($pages, self::statamicSchema());

        $this->assertSame('entry::contact', $style->positions['page_builder/hero#0']['button_link']);
        $this->assertSame(LinkDialect::SELF, $style->positions['page_builder/breadcrumbs#0/crumbs/crumb#2']['link']);
        $this->assertSame(LinkDialect::TITLE, $style->positions['page_builder/breadcrumbs#0/crumbs/crumb#2']['label']);
        $this->assertSame(['crumb', 'crumb', 'crumb'], $style->sequences['page_builder/breadcrumbs#0/crumbs']);
        $this->assertSame(['attrs' => ['textAlign' => 'center'], 'marks' => [['type' => 'bold']]], $style->markup['page_builder/hero.heading']['heading1']);
        $this->assertSame(['page_builder/hero.button_link' => 1, 'page_builder/hero.related' => 1, 'page_builder/breadcrumbs/crumbs/crumb.link' => 1], $style->links);

        $house = self::statamic();
        $result = $house->apply(['title' => 'Studio Winch', 'page_builder' => [
            ['type' => 'spacer'],
            ['type' => 'hero', 'heading' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Winches']]]]],
            ['type' => 'breadcrumbs'],
        ]], self::statamicSchema(), $style, null, 'Studio Winch');

        [$spacer, $hero, $crumbs] = $result->data['page_builder'];

        $this->assertSame('45/65', $spacer['height']);
        $this->assertSame(['level' => 1, 'textAlign' => 'center'], $hero['heading'][0]['attrs']);
        $this->assertSame([['type' => 'bold']], $hero['heading'][0]['content'][0]['marks']);
        $this->assertSame('entry::contact', $hero['button_link']);
        $this->assertSame(['new1', 'new2', 'new3'], array_column($crumbs['crumbs'], 'id'));
        $this->assertSame(['Home', 'Studio', 'Studio Winch'], array_column($crumbs['crumbs'], 'label'));
        $this->assertArrayNotHasKey('link', $crumbs['crumbs'][2]);

        // Entries to pick can't be stood in for; Statamic names only those.
        $this->assertSame(['Hero: Related'], $result->toFill);

        $linked = $house->linkToSelf($result->data, self::statamicSchema(), $style, 'abc', 'Studio Winch');
        $this->assertSame('entry::abc', $linked['page_builder'][2]['crumbs'][2]['link']);
    }

    public function test_statamic_points_a_usual_link_nothing_settles_at_example_dot_com_with_its_words(): void
    {
        $values = [];

        foreach (['p1' => 'entry::a', 'p2' => 'entry::b', 'p3' => 'entry::c'] as $id => $to) {
            $page = self::statamicPage($id, "Page {$id}")->values;
            $page['page_builder'][1]['button_link'] = $to;
            unset($page['page_builder'][1]['button_text']);
            $values[] = new EntryData($page, $id);
        }

        $style = self::statamic()->learn($values, self::statamicSchema());
        $result = self::statamic()->apply(['page_builder' => [['type' => 'hero']]], self::statamicSchema(), $style);

        $this->assertSame(LinkDialect::PLACEHOLDER_URL, $result->data['page_builder'][0]['button_link']);
        $this->assertSame(LinkDialect::PLACEHOLDER_TEXT, $result->data['page_builder'][0]['button_text']);
        $this->assertSame(['Hero (links to example.com for now)', 'Hero: Related'], $result->toFill);
    }

    public function test_craft_and_filament_name_references_inside_blocks_that_differ_but_not_images(): void
    {
        $schema = new Schema([new Field('builder', Kind::Blocks, sets: [
            'card' => new Set('Card', '', [new Field('items', Kind::Blocks, sets: ['item' => new Set('Item', '', [
                new Field('owner', Kind::Reference, 'Owner'),
                new Field('photo', Kind::Reference, 'Photo', files: true),
            ])])]),
        ])]);
        $draft = ['builder' => [['type' => 'card', 'items' => [['type' => 'item'], ['type' => 'item']]]]];

        $this->assertSame(['Card: Item 1', 'Card: Item 2'], (new HouseStyle(LayoutOptions::filament()))->apply($draft, $schema, new HouseRules)->toFill);
        $this->assertSame([], (new HouseStyle(LayoutOptions::statamic()))->apply($draft, $schema, new HouseRules)->toFill);
        $this->assertSame('Still to set by hand, as it differs from record to record: Card: Item 1; Card: Item 2.', (new HouseStyle(LayoutOptions::filament()))->apply($draft, $schema, new HouseRules)->note());
        $this->assertNull((new HouseResult([]))->note());
    }

    public function test_craft_copies_rich_text_the_models_agree_on_and_statamic_does_not(): void
    {
        $schema = new Schema([new Field('builder', Kind::Blocks, sets: ['intro' => new Set('Intro', '', [new Field('copy', Kind::RichText)])])]);
        $pages = [];

        foreach ([1, 2, 3] as $id) {
            $pages[] = new EntryData(['builder' => [['type' => 'intro', 'copy' => '<p>Gardens for the north.</p>']]], $id);
        }

        $craft = new HouseStyle(LayoutOptions::craft());
        $statamic = new HouseStyle(LayoutOptions::statamic(), new HtmlDialect);

        $this->assertSame('<p>Gardens for the north.</p>', $craft->apply(['builder' => [['type' => 'intro']]], $schema, $craft->learn($pages, $schema))->data['builder'][0]['copy']);
        $this->assertArrayNotHasKey('copy', $statamic->apply(['builder' => [['type' => 'intro']]], $schema, $statamic->learn($pages, $schema))->data['builder'][0]);
    }

    public function test_rules_round_trip_through_the_addons_array(): void
    {
        $style = self::craft()->learn([self::craftPage('A', 1), self::craftPage('B', 2)], self::craftSchema());

        $this->assertEquals($style, HouseRules::fromArray($style->toArray()));
        $this->assertFalse($style->isEmpty());
        $this->assertTrue(HouseRules::fromArray([])->isEmpty());
    }
}
