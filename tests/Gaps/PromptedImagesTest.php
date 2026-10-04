<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\EmptyImages;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Layout\FillRates;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Seo\H1Source;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use PHPUnit\Framework\TestCase;

/**
 * Which empty fields Finish this page raises: plain required fields are
 * the CMS's to report; an image the page looks like it needs (required,
 * the template's prominent one, or filled on most published siblings)
 * prompts, without blocking; an optional image few siblings use is left
 * alone.
 */
class PromptedImagesTest extends TestCase
{
    private const BODY = 'Our new roof garden sits above the café on Grey Street, with raised beds of herbs for the kitchen and a few tables among them.';

    public function test_plain_required_fields_left_empty_are_not_gaps(): void
    {
        $schema = new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('intro', Kind::LongText, 'Intro', required: true),
            new Field('published_on', Kind::Reference, 'Published on', required: true, type: 'date'),
            new Field('price', Kind::Number, 'Price', required: true),
            new Field('author', Kind::Reference, 'Author', required: true, type: 'entries'),
            new Field('category', Kind::Choice, 'Category', required: true, options: ['news' => 'News']),
            new Field('body', Kind::RichText, 'Body'),
        ]);

        $report = GapFinder::standard()->find(new GapContext(schema: $schema, entry: new EntryData(['title' => 'A roof garden', 'body' => self::BODY])));

        $this->assertSame([], $report->all());
        $this->assertSame(0, $report->count());
    }

    public function test_ghostwriter_markers_in_a_required_field_are_still_gaps(): void
    {
        $schema = new Schema([new Field('intro', Kind::LongText, 'Intro', required: true)]);

        $report = GapFinder::standard()->find(new GapContext(schema: $schema, entry: new EntryData(['intro' => 'Open from [[ask: opening date]]. Price TBC.'])));

        $this->assertSame(['ask', 'placeholder-text'], array_map(fn (Gap $gap) => $gap->kind->value, $report->all()));
        $this->assertSame(1, $report->count());
        $this->assertCount(1, $report->blocking());
    }

    public function test_an_empty_required_image_prompts_and_says_why(): void
    {
        $report = GapFinder::standard()->find($this->context(['body' => self::BODY], heroRequired: true));

        [$gap] = $report->all();

        $this->assertSame('image-empty|heroImage||0', $gap->id);
        $this->assertSame(Severity::Prompt, $gap->severity);
        $this->assertTrue($gap->counts());
        $this->assertTrue($gap->prompts());
        $this->assertFalse($gap->blocks());
        $this->assertSame('required', $gap->meta['why']);
        $this->assertSame('Hero image is required. Add one?', $gap->message()->english());
        $this->assertSame([FixAction::FindPhoto, FixAction::ChooseAsset], array_map(fn ($fix) => $fix->action, $gap->fixes));
        $this->assertSame(['count' => 1, 'blocking' => 0, 'prompting' => 1, 'suggestions' => 0], array_slice($report->toArray(), 0, 4));

        $this->assertTrue(PublishReadiness::standard()->check($this->context(['body' => self::BODY], heroRequired: true))->ready(), 'It never blocks: the CMS enforces required.');
    }

    public function test_an_optional_image_prompts_when_most_published_siblings_fill_it(): void
    {
        $gaps = GapFinder::standard()->find($this->context(['body' => self::BODY], pattern: new Pattern(entries: 20, filled: ['heroImage' => 0.85]), group: 'Journal'))->all();

        $this->assertSame(['image-empty|heroImage||0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame('siblings', $gaps[0]->meta['why']);
        $this->assertSame(0.85, $gaps[0]->meta['filled']);
        $this->assertSame('Hero image is empty, but most Journal entries have one. Add one?', $gaps[0]->message()->english());

        $exactly = GapFinder::standard()->find($this->context(['body' => self::BODY], pattern: new Pattern(entries: 20, filled: ['heroImage' => EmptyImages::SHARE])))->all();
        $this->assertSame('Hero image is empty. Pages like this usually have an image here.', $exactly[0]->message()->english(), 'Without the group\'s name, the general message.');
    }

    public function test_an_optional_image_few_siblings_use_is_no_gap_whatever_its_name(): void
    {
        $context = $this->context(['body' => self::BODY], pattern: new Pattern(entries: 20, filled: ['heroImage' => 0.3, 'gallery' => 0.1]));

        $this->assertSame([], GapFinder::standard()->find($context)->all());
    }

    public function test_with_too_few_siblings_a_hero_like_name_makes_it_the_prominent_image(): void
    {
        $few = new Pattern(entries: 2, filled: ['heroImage' => 0.0, 'gallery' => 0.0]);
        $gaps = GapFinder::standard()->find($this->context(['body' => self::BODY], pattern: $few))->all();

        $this->assertSame(['image-empty|heroImage||0'], array_map(fn (Gap $gap) => $gap->id, $gaps), 'Two entries say little; the gallery is no hero.');
        $this->assertSame('prominent', $gaps[0]->meta['why']);
        $this->assertSame('Hero image is the page\'s main image, and it\'s empty. Add one?', $gaps[0]->message()->english());

        $empty = GapFinder::standard()->find($this->context(['body' => self::BODY], pattern: new Pattern))->all();
        $this->assertSame(['image-empty|heroImage||0'], array_map(fn (Gap $gap) => $gap->id, $empty), 'No published entries yet: the name is all there is.');

        $this->assertSame([], GapFinder::standard()->find($this->context(['body' => self::BODY]))->all(), 'No pattern at all: nobody looked, so a name alone is nothing.');
    }

    public function test_hero_like_names(): void
    {
        $named = function (string $handle, string $label): bool {
            $schema = new Schema([new Field($handle, Kind::Reference, $label, files: true, meta: ['images' => true]), new Field('body', Kind::RichText, 'Body')]);

            return GapFinder::standard()->find(new GapContext(schema: $schema, entry: new EntryData(['body' => self::BODY]), pattern: new Pattern))->count() === 1;
        };

        $this->assertTrue($named('heroImage', 'Hero image'));
        $this->assertTrue($named('banner', 'Banner'));
        $this->assertTrue($named('featured_image', 'Featured image'));
        $this->assertTrue($named('mainPhoto', 'Main photo'));
        $this->assertTrue($named('cover', 'Cover'));
        $this->assertFalse($named('gallery', 'Gallery'));
        $this->assertFalse($named('thumbnail', 'Thumbnail'));
        $this->assertFalse($named('featured', 'Featured'), 'An image word must go with "featured".');
    }

    public function test_an_image_in_the_block_the_template_prints_the_h1_from_is_prominent(): void
    {
        $schema = new Schema([
            new Field('builder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text, 'Heading'), new Field('picture', Kind::Reference, 'Picture', files: true, meta: ['images' => true])]),
                'text' => new Set('Text', '', [new Field('copy', Kind::RichText, 'Copy'), new Field('picture', Kind::Reference, 'Picture', files: true, meta: ['images' => true])]),
            ]),
        ]);
        $values = ['builder' => [
            ['id' => 'h', 'type' => 'hero', 'heading' => 'A roof garden', 'picture' => []],
            ['id' => 't', 'type' => 'text', 'copy' => self::BODY, 'picture' => []],
        ]];
        $rarely = new Pattern(entries: 20, filled: ['hero.picture' => 0.2, 'text.picture' => 0.2]);
        $rendered = new RenderProfile('journal', H1Source::Field, 'hero.heading', renders: 2);

        $context = fn (?RenderProfile $profile) => new GapContext(schema: $schema, entry: new EntryData($values), richText: new MarkdownAsStored, pattern: $rarely, profile: $profile);

        $gaps = GapFinder::standard()->find($context($rendered))->all();
        $this->assertSame(['image-empty|builder/#h/picture||0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame('prominent', $gaps[0]->meta['why']);

        $this->assertSame([], GapFinder::standard()->find($context(null))->all(), 'Without a render, few siblings using it means no gap.');
        $this->assertSame([], GapFinder::standard()->find($context(new RenderProfile('journal', H1Source::Field, 'hero.heading')))->all(), 'Only a profile a render has shown.');
    }

    public function test_a_new_untouched_entry_is_not_prompted_until_a_draft_or_words(): void
    {
        $untouched = $this->context(['title' => 'A roof garden'], heroRequired: true);

        $this->assertFalse($untouched->engaged());
        $this->assertSame([], GapFinder::standard()->find($untouched)->all(), 'Its title alone doesn\'t make it more than new.');

        $drafted = $this->context(['title' => 'A roof garden'], heroRequired: true, session: new SessionGaps([['kind' => 'ask', 'path' => 'body', 'hint' => 'opening date']]));
        $this->assertTrue($drafted->engaged());
        $this->assertSame(1, GapFinder::standard()->find($drafted)->count(), 'A draft from Ghostwriter was applied.');

        $written = $this->context(['title' => 'A roof garden', 'body' => self::BODY], heroRequired: true);
        $this->assertTrue($written->engaged());
        $this->assertSame(1, GapFinder::standard()->find($written)->count());

        $few = $this->context(['title' => 'A roof garden', 'body' => 'Opening soon.'], heroRequired: true);
        $this->assertFalse($few->engaged(), 'Fewer than '.GapContext::ENGAGED_WORDS.' words.');
    }

    public function test_an_image_the_draft_left_for_a_person_prompts(): void
    {
        $session = new SessionGaps([['kind' => 'place', 'path' => 'gallery', 'label' => 'Gallery']]);
        $gaps = GapFinder::standard()->find($this->context(['body' => self::BODY], pattern: new Pattern(entries: 20, filled: ['heroImage' => 0.1, 'gallery' => 0.1]), session: $session))->all();

        $this->assertSame(['image-empty|gallery||0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame('draft', $gaps[0]->meta['why']);
    }

    public function test_fill_rates_are_counted_over_the_newest_siblings(): void
    {
        $schema = $this->schema(false);
        $entries = [];

        for ($i = 0; $i < 25; $i++) {
            $entries[] = new EntryData(['heroImage' => $i < 15 ? ['assets::hero.jpg'] : [], 'body' => 'Words']);
        }

        $pattern = FillRates::pattern($schema, $entries);

        $this->assertSame(FillRates::SIBLINGS, $pattern->entries);
        $this->assertSame(0.75, $pattern->filled['heroImage'], '15 of the newest 20.');
        $this->assertSame(0.0, $pattern->filled['gallery']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function context(array $values, bool $heroRequired = false, ?Pattern $pattern = null, string $group = '', ?SessionGaps $session = null): GapContext
    {
        return new GapContext(
            schema: $this->schema($heroRequired),
            entry: new EntryData($values + ['heroImage' => [], 'gallery' => []]),
            richText: new MarkdownAsStored,
            pattern: $pattern,
            session: $session ?? new SessionGaps,
            group: $group,
        );
    }

    private function schema(bool $heroRequired): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('heroImage', Kind::Reference, 'Hero image', required: $heroRequired, type: 'assets', files: true, meta: ['images' => true]),
            new Field('body', Kind::RichText, 'Body'),
            new Field('gallery', Kind::Reference, 'Gallery', type: 'assets', files: true, meta: ['images' => true]),
        ]);
    }
}
