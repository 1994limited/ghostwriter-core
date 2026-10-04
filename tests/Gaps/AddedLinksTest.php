<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Finish this page's "Check N links Ghostwriter added" (`links-added`,
 * SEO layer §12): one suggestion a link the SEO pass added, while the form
 * still has it, with Keep it and Remove the link. Never counted, never
 * blocking.
 */
final class AddedLinksTest extends TestCase
{
    private static function session(): Session
    {
        $session = Session::start(Format::Craft, 'page', []);
        $session->seo = ['links' => [
            ['unit' => 'u3', 'words' => 'planting plan we drew for you', 'href' => '{entry:7@1:url||/garden-services/planting-plans}', 'title' => 'Planting plans', 'type' => 'Garden services', 'url' => '/garden-services/planting-plans', 'why' => 'Follows a plan.'],
            ['unit' => 'u6', 'words' => 'tell us about your garden', 'href' => '{entry:9@1:url||/contact}', 'title' => 'Contact us', 'type' => 'Pages', 'url' => '/contact', 'why' => 'Get in touch.'],
            ['unit' => 'u7', 'words' => 'our journal', 'href' => '{entry:11@1:url||/journal}', 'title' => 'Journal', 'type' => 'Pages', 'url' => null, 'why' => ''],
        ]];

        return $session;
    }

    private static function context(string $body, ?Session $session = null): GapContext
    {
        return new GapContext(
            schema: new Schema([new Field('title', Kind::Text, 'Title'), new Field('body', Kind::RichText, 'Body')]),
            entry: new EntryData(['title' => 'Winter care', 'body' => $body]),
            richText: new HtmlDialect,
            session: SessionGaps::fromSession($session ?? self::session()),
        );
    }

    public function test_each_link_still_in_the_form_is_a_suggestion_to_check(): void
    {
        // As CKEditor's form holds them: the address with the reference after `#`, and as stored.
        $body = '<p>If you have a <a href="https://northfold.test/garden-services/planting-plans#entry:7@1:url">planting plan we drew for you</a>, we follow it.</p><p>Do <a href="{entry:9@1:url||/contact}">tell us about your garden</a>.</p><p>A <a href="https://example.org">link of your own</a>.</p>';
        $report = GapFinder::standard()->find(self::context($body));
        $gaps = array_values(array_filter($report->all(), fn ($gap) => $gap->kind === GapKind::LinksAdded));

        $this->assertCount(2, $gaps);
        $this->assertSame(Severity::Suggestion, $gaps[0]->severity);
        $this->assertSame(0, $report->count(), 'Never counted in the pill.');
        $this->assertSame(['planting plan we drew for you', 'tell us about your garden'], array_map(fn ($gap) => $gap->hint, $gaps));
        $this->assertSame(['dismiss', 'remove-link'], array_map(fn ($fix) => $fix->action->value, $gaps[0]->fixes));
        $this->assertSame('gaps.fix.keep-link', $gaps[0]->fixes[0]->label->key);
        $this->assertSame('Check 2 links Ghostwriter added. “planting plan we drew for you” goes to Planting plans (/garden-services/planting-plans). Keep it, or remove the link and keep the words.', $gaps[0]->message()->english());
        $this->assertSame('{entry:9@1:url||/contact}', $gaps[1]->meta['href']);
        $this->assertSame('Contact us', $gaps[1]->meta['title']);

        $this->assertTrue(PublishReadiness::standard()->check(self::context($body))->ready(), 'SEO never blocks publishing.');
    }

    public function test_a_link_removed_since_is_no_longer_a_step(): void
    {
        $gaps = array_values(array_filter(GapFinder::standard()->find(self::context('<p>Do <a href="{entry:9@1:url||/contact}">tell us about your garden</a>, then plan it.</p>'))->all(), fn ($gap) => $gap->kind === GapKind::LinksAdded));

        $this->assertCount(1, $gaps);
        $this->assertSame('Check the link Ghostwriter added. “tell us about your garden” goes to Contact us (/contact). Keep it, or remove the link and keep the words.', $gaps[0]->message()->english());
        $this->assertSame([], array_filter(GapFinder::standard()->find(self::context('<p>Do tell us about your garden.</p>'))->all(), fn ($gap) => $gap->kind === GapKind::LinksAdded));
    }

    public function test_no_session_links_no_steps(): void
    {
        $session = Session::start(Format::Craft, 'page', []);

        $this->assertSame([], array_filter(GapFinder::standard()->find(self::context('<p>Do <a href="{entry:9@1:url||/contact}">tell us</a>.</p>', $session))->all(), fn ($gap) => $gap->kind === GapKind::LinksAdded));
        $this->assertTrue(SessionGaps::fromSession($session)->isEmpty());
        $this->assertFalse(SessionGaps::fromSession(self::session())->isEmpty());
    }
}
