<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkInsertContractTest;

/**
 * LinkInsertContract with core's HTML dialect as the apply path (what
 * Craft's CKEditor fields are written with) and a renderer that resolves
 * Craft's reference tags as Craft does.
 */
final class MemoryLinkInsertContractTest extends LinkInsertContractTest
{
    protected function inlineLinks(): InlineLinks
    {
        return new CraftLinks;
    }

    protected function linkTarget(): DigestEntry
    {
        return new DigestEntry(new EntryRef('pages', '9', 1), 'Contact us', '/contact', 'Book a visit.', '{entry:9@1:url}', 'Pages');
    }

    protected function linkField(): Field
    {
        return new Field('body', Kind::RichText, 'Body');
    }

    protected function storedMarkdown(string $markdown, Field $field): string
    {
        $dialect = new HtmlDialect;

        return (string) $dialect->toMarkdown($dialect->fromMarkdown($markdown, $field), $field);
    }

    protected function renderedHref(string $href): ?string
    {
        return preg_match('/^\{entry:9(?:@1)?:url(?:\|\|.*)?\}$/', $href) === 1 ? 'https://northfold.test/contact' : null;
    }

    protected function targetUrl(): string
    {
        return 'https://northfold.test/contact';
    }
}
