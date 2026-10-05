<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ProposedLinksContractTest;

/**
 * ProposedLinksContract with core's HTML dialect as the stored form (what
 * Craft's CKEditor and Filament's RichEditor keep) and Craft's reference
 * tag as the link.
 */
final class MemoryProposedLinksContractTest extends ProposedLinksContractTest
{
    protected function finishContext(string $markdown, ?LinkProposals $proposals = null): GapContext
    {
        $body = new Field('body', Kind::RichText, 'Body');
        $dialect = new HtmlDialect;

        return new GapContext(
            schema: new Schema([new Field('title', Kind::Text, 'Title'), $body]),
            entry: new EntryData(['title' => 'Winter garden care', 'body' => $dialect->fromMarkdown($markdown, $body)]),
            richText: $dialect,
            proposals: $proposals,
        );
    }

    protected function proposalPath(): FieldPath
    {
        return FieldPath::of('body');
    }

    protected function proposalHref(): string
    {
        return '{entry:9@1:url||https://northfold.test/contact}';
    }
}
