<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview;

use League\CommonMark\CommonMarkConverter;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\UnitsTest;
use PHPUnit\Framework\TestCase;

/**
 * The page the locator's Node tests read (tests/Fixtures/preview/page.json):
 * a draft marked by PreviewMarkers and printed by a plain template, so the
 * PHP that writes markers and the JavaScript that reads them are tested
 * against each other. Run with GHOSTWRITER_UPDATE_FIXTURES=1 to rewrite it.
 */
final class LocatorFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/preview/page.json';

    public function test_the_locators_fixture_is_what_core_marks_today(): void
    {
        $data = UnitsTest::draft();
        $data['page_builder'][1]['text'] = (new HtmlDialect)->fromMarkdown(UnitsTest::BODY, new Field('text', Kind::RichText));
        $preview = (new PreviewMarkers)->mark($data, UnitsTest::schema(), Units::fromDraft(UnitsTest::draft(), UnitsTest::schema()));
        $fixture = json_encode(['html' => self::render($preview->data), 'map' => $preview->map->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            file_put_contents(self::FIXTURE, $fixture);
        }

        $this->assertSame($fixture, (string) @file_get_contents(self::FIXTURE), 'Rewrite it with GHOSTWRITER_UPDATE_FIXTURES=1.');
    }

    public function test_the_locator_reads_the_payloads_core_writes(): void
    {
        $locator = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/preview/locator.js');

        $this->assertStringContainsString('export const PAYLOAD = '.PreviewMarkers::PAYLOAD.';', $locator);
        $this->assertStringContainsString('\\u{E0067}\\u{E0077}([\\u{E0020}-\\u{E007E}]{1,16})\\u{E007F}', $locator);
    }

    /**
     * A site's template, as plain as they come: the text block prints its
     * rich text with no wrapper, as the Craft test site's does.
     *
     * @param  array<string, mixed>  $data
     */
    private static function render(array $data): string
    {
        $e = fn (mixed $value) => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
        $html = '<header id="site"><a href="/">Northfold</a><nav><a href="/services">Services</a></nav></header>'
            .'<main id="main"><h1 id="title">'.$e($data['title']).'</h1><p id="intro">'.$e($data['intro']).'</p>';

        foreach ($data['page_builder'] as $i => $block) {
            $html .= match ($block['type']) {
                'hero' => '<section id="block-'.$i.'" class="hero"><h2>'.$e($block['heading']).'</h2><img src="/img/asset/YXNzZXRz/garden.jpg?w=1200" alt=""></section>',
                'text' => $block['text'],
                'faq' => '<dl id="block-'.$i.'" class="faq">'.implode('', array_map(fn (array $row) => '<dt>'.$e($row['question']).'</dt><dd>'.$e($row['answer']).'</dd>', $block['questions'])).'</dl>',
                'ticks' => '<div id="block-'.$i.'" class="ticks"><ul>'.implode('', array_map(fn ($item) => '<li>'.$e($item).'</li>', $block['items'])).'</ul></div>',
                default => '',
            };
        }

        return $html.'<div id="notes">'.trim((string) (new CommonMarkConverter)->convert($data['notes'])).'</div></main><footer id="foot"><p>© Northfold Gardens</p></footer>';
    }
}
