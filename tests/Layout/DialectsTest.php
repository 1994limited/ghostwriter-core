<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use PHPUnit\Framework\TestCase;

class DialectsTest extends TestCase
{
    public function test_html_is_written_from_markdown_with_raw_html_escaped(): void
    {
        $html = new HtmlDialect;
        $field = new Field('body', Kind::RichText);

        $this->assertSame('', $html->fromMarkdown('', $field));
        $this->assertSame("<h2>Hi</h2>\n<p>&lt;img src=x onerror=alert(1)&gt; <a>link</a></p>", $html->fromMarkdown("## Hi\n\n<img src=x onerror=alert(1)> [link](javascript:alert(1))", $field));
    }

    public function test_html_is_shown_as_markdown_and_a_markdown_field_as_it_is(): void
    {
        $craft = new HtmlDialect;
        $filament = new HtmlDialect(new HtmlToMarkdown(embeds: []));
        $html = '<p>Hi <strong>there</strong></p><craft-entry data-entry-id="4">Card</craft-entry>';

        $this->assertSame('Hi **there**', $craft->toMarkdown($html, new Field('body', Kind::RichText)));
        $this->assertStringContainsString('Card', (string) $filament->toMarkdown($html, new Field('body', Kind::RichText)));
        $this->assertSame('Already **markdown**', $craft->toMarkdown(" Already **markdown**\n", new Field('notes', Kind::RichText, meta: ['format' => 'markdown'])));
        $this->assertNull($craft->toMarkdown(['type' => 'doc'], new Field('body', Kind::RichText)));
    }

    public function test_html_dressing_is_learned_where_most_agree_and_put_on_plain_elements(): void
    {
        $html = new HtmlDialect;
        $shapes = $html->shapes([
            '<h1 class="big"><span style="color:red">One</span></h1><p>Plain</p>',
            '<h1 class="big"><span style="color:red">Two</span></h1><p>Plain</p><h1>Second heading, not counted</h1>',
            '<h1>Three</h1>',
            '   ',
        ]);

        $this->assertSame(['h1' => ['attributes' => ['class' => 'big'], 'wrappers' => [['span', ['style' => 'color:red']]]]], $shapes);
        $this->assertSame('<h1 class="big"><span style="color:red">New</span></h1><h1 id="mine">Kept</h1><p>Plain</p>', $html->dress('<h1>New</h1><h1 id="mine">Kept</h1><p>Plain</p>', $shapes));
        $this->assertSame('<p>No shapes</p>', $html->dress('<p>No shapes</p>', []));
        $this->assertTrue($html->isWritten('<p>x</p>'));
        $this->assertFalse($html->isWritten("  \n"));
        $this->assertFalse($html->isWritten(null));
    }

    public function test_bard_fits_the_rich_text_interface(): void
    {
        $bard = new BardDialect;
        $field = new Field('body', Kind::RichText, type: 'bard');
        $nodes = $bard->fromMarkdown('# Hello', $field);

        $this->assertSame([['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Hello']]]], $nodes);
        $this->assertSame('# Hello', $bard->toMarkdown($nodes, $field));
        $this->assertSame('Plain', $bard->toMarkdown(' Plain ', $field));
        $this->assertTrue($bard->isWritten($nodes));
        $this->assertFalse($bard->isWritten([]));
    }

    public function test_statamic_links(): void
    {
        $links = new StatamicLinks;
        $link = new Field('link', Kind::Reference, type: 'link');
        $buttonLink = new Field('button_link', Kind::Reference, type: 'link');

        $this->assertTrue($links->holdsLinks($link));
        $this->assertTrue($links->holdsLinks(new Field('related', Kind::Reference, type: 'entries')));
        $this->assertFalse($links->holdsLinks(new Field('image', Kind::Reference, type: 'assets')));
        $this->assertTrue($links->hasLink(['', 'entry::1']));
        $this->assertFalse($links->hasLink(['', ' ']));
        $this->assertTrue($links->looksLikeLink('mailto:a@b.c'));
        $this->assertFalse($links->looksLikeLink('Home'));
        $this->assertSame(['link' => LinkDialect::SELF, 'label' => LinkDialect::TITLE, 'related' => LinkDialect::SELF, 'other' => ['entry::2']], $links->generalise(['link' => 'entry::1', 'label' => 'About', 'related' => ['1'], 'other' => ['entry::2']], 1, 'About'));
        $this->assertSame('entry::5', $links->toSelf(5));
        $this->assertSame(['link' => LinkDialect::PLACEHOLDER_URL, 'text' => LinkDialect::PLACEHOLDER_TEXT, 'link_text' => LinkDialect::PLACEHOLDER_TEXT], $links->placeholder($link, [new Field('text', Kind::Text), new Field('link_text', Kind::Text), $link]));
        $this->assertSame(['button_link' => LinkDialect::PLACEHOLDER_URL, 'button_text' => LinkDialect::PLACEHOLDER_TEXT], $links->placeholder($buttonLink, [new Field('button_text', Kind::Text), new Field('text', Kind::Text)]));
        $this->assertNull($links->placeholder(new Field('related', Kind::Reference, type: 'entries'), []));
    }

    public function test_craft_links(): void
    {
        $links = new CraftLinks(hyper: [Addons::HYPER], link: [Addons::LINK]);
        $hyper = new Field('cta', Kind::Reference, type: Addons::HYPER);
        $link = new Field('button', Kind::Reference, type: Addons::LINK);

        $this->assertTrue($links->holdsLinks($hyper));
        $this->assertTrue($links->holdsLinks($link));
        $this->assertFalse($links->holdsLinks(new Field('image', Kind::Reference, type: 'craft\\fields\\Assets')));
        $this->assertFalse((new CraftLinks)->holdsLinks($hyper));
        $this->assertTrue($links->hasLink([['linkValue' => [4]]]));
        $this->assertFalse($links->hasLink([['linkValue' => [], 'linkText' => 'Words']]));
        $this->assertFalse($links->looksLikeLink('https://example.com'));

        $this->assertSame(
            ['cta' => [['type' => 'entry', 'linkValue' => LinkDialect::SELF, 'linkText' => LinkDialect::TITLE]], 'button' => ['value' => LinkDialect::SELF, 'label' => 'Other'], 'title' => 'About'],
            $links->generalise(['cta' => [['type' => 'entry', 'linkValue' => [12], 'linkText' => 'About']], 'button' => ['value' => '12', 'label' => 'Other'], 'title' => 'About'], 12, 'About'),
        );
        $this->assertSame([12], $links->toSelf(12));
        $this->assertSame(['cta' => [['type' => CraftLinks::HYPER_URL, 'linkValue' => LinkDialect::PLACEHOLDER_URL, 'linkText' => LinkDialect::PLACEHOLDER_TEXT]]], $links->placeholder($hyper, []));
        $this->assertSame(['button' => ['type' => 'url', 'value' => LinkDialect::PLACEHOLDER_URL, 'label' => LinkDialect::PLACEHOLDER_TEXT]], $links->placeholder($link, []));
        $this->assertNull($links->placeholder(new Field('image', Kind::Reference), []));
    }

    public function test_no_links(): void
    {
        $links = new NoLinks;
        $field = new Field('label', Kind::Text);

        $this->assertFalse($links->holdsLinks($field));
        $this->assertFalse($links->hasLink('https://example.com'));
        $this->assertFalse($links->looksLikeLink('https://example.com'));
        $this->assertSame(['label' => 'About', 'value' => 3], $links->generalise(['label' => 'About', 'value' => 3], 3, 'About'));
        $this->assertNull($links->placeholder($field, []));
        $this->assertSame([3], $links->toSelf(3));
    }

    public function test_the_presets_say_how_each_addon_differs(): void
    {
        $statamic = LayoutOptions::statamic();
        $craft = LayoutOptions::craft();
        $filament = LayoutOptions::filament();

        $this->assertSame(['collection', 'section', 'section'], [$statamic->group, $craft->group, $filament->group]);
        $this->assertSame(['page', 'page', 'record'], [$statamic->item, $craft->item, $filament->item]);
        $this->assertSame([false, true, true], [$statamic->richTextInPositions, $craft->richTextInPositions, $filament->richTextInPositions]);
        $this->assertSame([LayoutOptions::NAME_LINKS, LayoutOptions::NAME_NESTED, LayoutOptions::NAME_NESTED], [$statamic->unsettled, $craft->unsettled, $filament->unsettled]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', ($statamic->newId ?? fn () => '')());
        $this->assertNull($craft->newId);
        $this->assertSame([false, false, true], [$statamic->kindsFromAnyBuilder, $craft->kindsFromAnyBuilder, $filament->kindsFromAnyBuilder]);
        $this->assertSame(['does not exist in', 'cannot go in'], [$statamic->unknownBlock, $filament->unknownBlock]);
        $this->assertContains('updated_by', $statamic->bookkeeping);
        $this->assertSame(['title', 'slug', 'id'], $craft->bookkeeping);
    }

    public function test_unsettled_references_are_named_one_of_two_ways(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LayoutOptions(unsettled: 'all');
    }
}
