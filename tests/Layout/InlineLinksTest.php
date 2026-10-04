<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\FilamentLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use PHPUnit\Framework\TestCase;

/**
 * InlineLinks::inlineHref() (SEO layer §7.4): each CMS's reference for an
 * inline link to another page, from the link index's row; and the HTML
 * dialect keeps Craft's reference tags as CKEditor stores them.
 */
final class InlineLinksTest extends TestCase
{
    private static function page(mixed $link, ?string $url = '/journal/winter-care'): DigestEntry
    {
        return new DigestEntry(new EntryRef('journal', 'abc'), 'Winter care', $url, '', $link, 'Journal');
    }

    public function test_statamic_links_an_entry_by_reference_and_a_term_by_address(): void
    {
        $links = new StatamicLinks;

        $this->assertSame('statamic://entry::abc-123', $links->inlineHref(self::page('entry::abc-123')));
        $this->assertSame('statamic://entry::abc-123', $links->inlineHref(self::page('statamic://entry::abc-123')));
        $this->assertSame('/plants/roses', $links->inlineHref(self::page('/plants/roses')));
        $this->assertSame('/journal/winter-care', $links->inlineHref(self::page(null)));
        $this->assertNull($links->inlineHref(self::page(null, null)));
    }

    public function test_craft_links_by_reference_tag_with_the_address_as_fallback(): void
    {
        $links = new CraftLinks;

        $this->assertSame('{entry:12@1:url||/journal/winter-care}', $links->inlineHref(self::page('{entry:12@1:url}')));
        $this->assertSame('{category:5@2:url||/journal/winter-care}', $links->inlineHref(self::page('{category:5@2:url}')));
        $this->assertSame('{entry:12:url}', $links->inlineHref(self::page('{entry:12}', null)));
        $this->assertNull($links->inlineHref(self::page('/journal/winter-care')));
        $this->assertSame('entry:12', LinkCandidates::linkKey('{entry:12@1:url||/journal/winter-care}'));
        $this->assertSame('entry:12', LinkCandidates::linkKey('https://northfold.test/journal/winter-care#entry:12@1:url'), 'CKEditor\'s form of it, as the editor shows it.');
    }

    public function test_filament_links_by_public_address_and_no_links_by_none(): void
    {
        $this->assertSame('https://northfold.test/posts/winter-care', (new FilamentLinks)->inlineHref(self::page('https://northfold.test/posts/winter-care')));
        $this->assertSame('/journal/winter-care', (new FilamentLinks)->inlineHref(self::page(null)));
        $this->assertNull((new FilamentLinks)->inlineHref(self::page('admin/posts/1', null)));
        $this->assertNull((new NoLinks)->inlineHref(self::page('entry::abc')));
        $this->assertTrue((new FilamentLinks)->supportsLinks(new Field('body', Kind::RichText)));
        $this->assertFalse((new FilamentLinks)->holdsLinks(new Field('url', Kind::Text)));
    }

    public function test_html_keeps_a_reference_tag_as_ckeditor_stores_it(): void
    {
        $html = (new HtmlDialect)->fromMarkdown('Read [our winter guide]({entry:12@1:url||/journal/winter-care}) and [this](https://a.test/?a=1&b=2).', new Field('body', Kind::RichText));

        $this->assertSame('<p>Read <a href="{entry:12@1:url||/journal/winter-care}">our winter guide</a> and <a href="https://a.test/?a=1&amp;b=2">this</a>.</p>', $html);
        $this->assertSame('Read [our winter guide]({entry:12@1:url||/journal/winter-care}) and [this](https://a.test/?a=1&b=2).', (new HtmlDialect)->toMarkdown($html, new Field('body', Kind::RichText)));
    }
}
