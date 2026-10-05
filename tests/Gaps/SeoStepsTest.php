<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\FewLinks;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\LongHeadings;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Finish this page's SEO steps of phase 1 (SEO layer §12): "Shorten a
 * heading" (`heading-long`) and "Link to your other pages" (`few-links`).
 * Both are suggestions: never counted in the pill, never blocking, and
 * found for nothing.
 */
final class SeoStepsTest extends TestCase
{
    private const LONG = 'What to do in a walled garden in late winter, before the first warm weekend arrives';

    /**
     * @param  array<int, string>  $hosts
     */
    private static function context(string $body, array $hosts = []): GapContext
    {
        return new GapContext(
            schema: new Schema([new Field('title', Kind::Text, 'Title'), new Field('body', Kind::RichText, 'Body'), new Field('intro', Kind::LongText, 'Intro')]),
            entry: new EntryData(['title' => 'Winter care', 'body' => $body, 'intro' => 'A short intro.']),
            richText: new HtmlDialect,
            hosts: $hosts,
        );
    }

    private static function words(int $count): string
    {
        return '<p>'.implode(' ', array_fill(0, $count, 'mulch')).'.</p>';
    }

    public function test_a_heading_over_seventy_characters_is_a_suggestion_to_shorten(): void
    {
        $body = '<h2>'.self::LONG.'</h2><p>Cut back.</p><h2>Short heading</h2><h3><strong>'.self::LONG.'</strong></h3>';
        $report = GapFinder::standard()->find(self::context($body));
        $gaps = $report->ofKind(GapKind::HeadingLong);

        $this->assertCount(2, $gaps, 'The same long heading twice is two steps.');
        $this->assertSame([0, 1], array_map(fn ($gap) => $gap->occurrence, $gaps));
        $this->assertSame(self::LONG, $gaps[0]->hint);
        $this->assertSame(Severity::Suggestion, $gaps[0]->severity);
        $this->assertSame(['write-for-me', 'focus'], array_map(fn ($fix) => $fix->action->value, $gaps[0]->fixes));
        $this->assertSame('model', $gaps[0]->fixes[0]->cost->value);
        $this->assertSame('This heading in Body is 83 characters. Over 70, it\'s hard to scan and gets cut off in search results.', $gaps[0]->message()->english());
        $this->assertSame('gaps.step.heading-long', $gaps[0]->meta['step']);
        $this->assertSame(0, $report->count(), 'Never counted in the pill.');
        $this->assertTrue(PublishReadiness::standard()->check(self::context($body))->ready(), 'SEO never blocks publishing.');
    }

    public function test_headings_within_the_limit_and_markdown_around_the_words_are_fine(): void
    {
        $this->assertSame([], LongHeadings::long("## Short\n\nA paragraph that is very long indeed, far longer than seventy characters, but not a heading."));
        $this->assertSame('A link and bold words', LongHeadings::plain('A [link](/x) and **bold** words'));
        $this->assertSame([], GapFinder::standard()->find(self::context('<h2>Garden jobs for late February</h2>'))->ofKind(GapKind::HeadingLong));
    }

    public function test_a_long_page_with_no_link_to_the_site_is_a_suggestion_to_link(): void
    {
        $report = GapFinder::standard()->find(self::context(self::words(320).'<p>See <a href="https://rhs.org.uk/mulch">the RHS</a> or <a href="#gw-link:contact">get in touch</a>.</p>'));
        $gaps = $report->ofKind(GapKind::FewLinks);

        $this->assertCount(1, $gaps, 'Links to other sites and links still to choose don\'t count.');
        $this->assertSame('body', $gaps[0]->path->toString(), 'On the field with the most words.');
        $this->assertSame(['suggest-links', 'focus', 'dismiss'], array_map(fn ($fix) => $fix->action->value, $gaps[0]->fixes));
        $this->assertSame(['gaps.fix.suggest-links', 'gaps.fix.add-links', 'gaps.fix.skip'], array_map(fn ($fix) => $fix->label->key, $gaps[0]->fixes));
        $this->assertSame(['model', 'free', 'free'], array_map(fn ($fix) => $fix->cost->value, $gaps[0]->fixes), 'Suggest links uses Ghostwriter.');
        $this->assertTrue($gaps[0]->fixes[0]->primary);
        $this->assertSame('gaps.fix.suggesting-links', $gaps[0]->meta['running'], 'What the button says while it runs.');
        $this->assertSame(Severity::Suggestion, $gaps[0]->severity);
        $this->assertSame(1, $report->count(), 'Only the link to choose is counted.');
    }

    public function test_a_link_to_the_site_a_short_page_or_an_untouched_one_isnt(): void
    {
        $finds = fn (GapContext $context) => GapFinder::standard()->find($context)->ofKind(GapKind::FewLinks) !== [];

        $this->assertFalse($finds(self::context(self::words(320).'<p><a href="/contact">Contact us</a>.</p>')), 'A path is the site.');
        $this->assertFalse($finds(self::context(self::words(320).'<p><a href="statamic://entry::abc">Contact us</a>.</p>')), 'A CMS reference is the site.');
        $this->assertFalse($finds(self::context(self::words(320).'<p><a href="https://www.northfold.test/contact">Contact us</a>.</p>', ['northfold.test'])), 'An address on the site\'s own host.');
        $this->assertTrue($finds(self::context(self::words(320).'<p><a href="https://www.northfold.test/contact">Contact us</a>.</p>')), 'Without the hosts, a full address is another site.');
        $this->assertFalse($finds(self::context(self::words(FewLinks::MIN_WORDS - 10))), 'Under 300 words.');
        $this->assertFalse($finds(self::context('')), 'An untouched entry is never nagged.');
    }

    public function test_every_detector_core_ships_is_free(): void
    {
        foreach (GapFinder::standard()->detectors() as $detector) {
            $this->assertInstanceOf(Detector::class, $detector);
            $this->assertFalse($detector->usesModel(), $detector::class.' must not call a model.');
        }
    }
}
