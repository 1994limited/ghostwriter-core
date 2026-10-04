<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\H1Source;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Outline;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A preview's outline read into a render profile (seo-layer-design.md
 * §6.2), over the same rendered pages the locator's outline() is tested
 * on (tests/Fixtures/seo/outlines.json).
 */
final class RenderProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function outlines(): iterable
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/seo/outlines.json'), true);

        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('outlines')]
    public function test_an_outline_gives_the_profile(array $case): void
    {
        $profile = RenderProfile::fromOutline('pages', Outline::fromArray($case['outline']), '2026-10-04T10:00:00Z');

        $this->assertSame($case['profile'], [
            'h1' => $profile->h1->value,
            'h1Field' => $profile->h1Field,
            'fieldLevels' => $profile->fieldLevels,
            'bodyOwnsH1' => $profile->bodyOwnsH1,
        ]);
        $this->assertSame(1, $profile->renders);
        $this->assertEquals($profile, RenderProfile::fromArray($profile->toArray()));
    }

    public function test_where_a_body_starts(): void
    {
        $body = new Field('body', Kind::RichText);
        $text = new Field('body', Kind::RichText);

        $this->assertSame(2, RenderProfile::default()->top($body));
        $this->assertSame(2, (new RenderProfile('k', H1Source::Static, null))->top($body));
        $this->assertSame(2, (new RenderProfile('k', H1Source::None, null))->top($body), 'No h1 and no evidence the body owns it: H2 as usual.');
        $this->assertSame(1, (new RenderProfile('k', H1Source::None, null, [], ['body']))->top($body));
        $this->assertSame(2, (new RenderProfile('k', H1Source::None, null, [], ['body']))->top($body->with(meta: ['headings' => [2, 3]])), 'An editor that can\'t make # never owns the h1.');

        $sections = new RenderProfile('k', fieldLevels: ['section.heading' => 2, 'hero.heading' => 1]);
        $this->assertSame(3, $sections->top($text, 'section'), 'One below the block\'s own heading.');
        $this->assertSame(2, $sections->top($text, 'hero'));
        $this->assertSame(2, $sections->top($text, 'quote'), 'A block with no heading field of its own starts at the page\'s.');
        $this->assertSame([3, 4, 5, 6], HeadingPolicy::for($text, $sections, 'section')->levels());
        $this->assertSame(1, HeadingPolicy::for(Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings_from' => 1]))->top, 'A field that says where its headings start starts there.');
    }

    public function test_two_renders_must_agree_before_a_stored_profile_changes(): void
    {
        $title = RenderProfile::fromOutline('pages', Outline::fromArray([['level' => 1, 'text' => 'Winter care', 'field' => 'title']]), 'one');
        $none = RenderProfile::fromOutline('pages', Outline::fromArray([['level' => 2, 'text' => 'Visits', 'field' => 'body', 'inContent' => true]]), 'two');

        $first = RenderProfile::default('pages')->observe($title);
        $this->assertSame(H1Source::Title, $first->h1);
        $this->assertSame(1, $first->renders, 'The first render is taken straight away.');

        $again = $first->observe($title);
        $this->assertSame(2, $again->renders);

        $odd = $again->observe($none);
        $this->assertSame(H1Source::Title, $odd->h1, 'One odd render doesn\'t flip it.');
        $this->assertNotNull($odd->pending);
        $this->assertNull($odd->problem());

        $back = $odd->observe($title);
        $this->assertSame(H1Source::Title, $back->h1);
        $this->assertNull($back->pending, 'An agreeing render clears what was pending.');

        $flipped = $odd->observe($none);
        $this->assertSame(H1Source::None, $flipped->h1, 'Two agreeing renders do.');
        $this->assertSame('no-h1', $flipped->problem());
        $this->assertStringContainsString('print no main heading (H1)', (string) $flipped->with(label: 'Garden services')->note());
        $this->assertStringContainsString('Pages in Garden services', (string) $flipped->with(label: 'Garden services')->note());
        $this->assertEquals($odd, RenderProfile::fromArray($odd->toArray()), 'What is pending is stored with it.');
    }

    public function test_the_entries_can_say_the_body_owns_the_h1_before_any_render(): void
    {
        $schema = new Schema([new Field('title', Kind::Text), new Field('body', Kind::RichText)]);
        $owning = array_map(fn (int $i) => new EntryData(['title' => "T{$i}", 'body' => "<h1>Heading {$i}</h1><p>Text.</p><h2>More</h2><p>Text.</p>"]), range(1, 5));
        $usual = array_map(fn (int $i) => new EntryData(['title' => "T{$i}", 'body' => '<p>Text.</p><h2>More</h2><p>Text.</p>']), range(1, 5));

        $profile = RenderProfile::resolve('pages', null, $schema, $owning, new HtmlDialect);
        $this->assertSame(['body'], $profile->bodyOwnsH1);
        $this->assertSame(1, $profile->top($schema->fields[1]));
        $this->assertFalse($profile->rendered());
        $this->assertNull($profile->problem(), 'Evidence isn\'t a render: no developer note.');

        $this->assertSame(2, RenderProfile::resolve('pages', null, $schema, $usual, new HtmlDialect)->top($schema->fields[1]));
        $this->assertSame(2, RenderProfile::resolve('pages', null, $schema, [...array_slice($owning, 0, 3), ...array_slice($usual, 0, 2)], new HtmlDialect)->top($schema->fields[1]), 'Under 80% isn\'t enough.');
        $this->assertSame(2, RenderProfile::resolve('pages', null, $schema, array_slice($owning, 0, 2), new HtmlDialect)->top($schema->fields[1]), 'Two entries aren\'t evidence.');

        $rendered = RenderProfile::fromOutline('pages', Outline::fromArray([['level' => 1, 'text' => 'Winter care', 'field' => 'title']]));
        $this->assertSame($rendered, RenderProfile::resolve('pages', $rendered, $schema, $owning, new HtmlDialect), 'A render wins over the entries.');
    }

    public function test_an_outline_keeps_only_headings(): void
    {
        $outline = Outline::fromArray([['level' => 'h2', 'text' => '  Visits '], ['level' => 7], 'nonsense', ['text' => 'no level']]);

        $this->assertCount(1, $outline->headings);
        $this->assertSame(['level' => 2, 'text' => 'Visits', 'field' => null, 'unit' => null, 'inContent' => false], $outline->headings[0]->toArray());
    }
}
