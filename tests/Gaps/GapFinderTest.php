<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryStockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryAssets;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryLinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gap finder over the mockup's "Capacitor" page, in Statamic's shapes,
 * and each kind of text field in the four shapes text is stored in.
 */
class GapFinderTest extends TestCase
{
    public function test_the_capacitor_page_has_every_gap_the_mockup_shows_in_form_order(): void
    {
        $report = GapFinder::standard()->find($this->context());

        $this->assertSame([
            'expected|summary||0',
            'image-placeholder|featured_image||0',
            'ask|page_builder/#h1/intro|how long a typical capacitor project takes|0',
            'link|page_builder/#c1/button_link|button-link|0',
            'link|page_builder/#t1/copy|contact-page|0',
            'leftover-token|page_builder/#t1/copy|[[item]]|0',
            'link-empty|related||0',
            'placeholder-text|seo_description|tbc|0',
        ], array_map(fn (Gap $gap) => $gap->id, $report->all()));

        $this->assertSame(6, $report->count(), 'The pill counts what blocks or is required.');
        $this->assertSame(5, count($report->blocking()));
        $this->assertSame(2, $report->suggestions());
    }

    public function test_a_fact_to_add_asks_and_never_offers_a_value(): void
    {
        $gap = GapFinder::standard()->find($this->context())->ofKind(GapKind::Ask)[0];

        $this->assertSame(Severity::Blocks, $gap->severity);
        $this->assertSame('Hero: Intro', $gap->label);
        $this->assertSame('page_builder.0.intro', $gap->path->dotted());
        $this->assertSame('It ships to the App Store and Google Play without a rebuild in [[ask: how long a typical Capacitor project takes]].', $gap->excerpt);
        $this->assertSame([FixAction::Answer, FixAction::WriteAround], array_map(fn ($fix) => $fix->action, $gap->fixes));
        $this->assertTrue($gap->fixes[0]->primary);
        $this->assertNull($gap->fixes[0]->value, 'Facts come only from the editor: no value is ever suggested.');
        $this->assertSame(FixCost::Model, $gap->fixes[1]->cost);
        $this->assertSame('I left a gap in Hero: Intro: how long a typical Capacitor project takes. Only you know this. What should it say?', $gap->message()->english());
        $this->assertSame('gaps.speech.ask', $gap->toArray()['speech']);
        $this->assertSame('Fill this in', (new Message($gap->kind->speech()))->english());
    }

    public function test_links_to_choose_are_found_inline_and_in_link_fields_with_candidates(): void
    {
        $targets = new MemoryLinkTargets(['c' => ['title' => 'Contact', 'slug' => 'contact-page', 'url' => '/contact'], 'b' => ['title' => 'Blog']]);
        [$field, $inline] = GapFinder::standard()->find($this->context(targets: $targets))->ofKind(GapKind::LinkToChoose);

        $this->assertFalse($field->meta['inline']);
        $this->assertSame('Call to action: Button link doesn\'t link anywhere yet.', $field->message()->english());
        $this->assertSame([FixAction::ChooseEntry], array_map(fn ($fix) => $fix->action, $field->fixes), 'Nothing matches "button link".');

        $this->assertTrue($inline->meta['inline']);
        $this->assertSame('Talk to us about Capacitor', $inline->meta['words']);
        $this->assertSame([FixAction::Link, FixAction::ChooseEntry, FixAction::RemoveLink], array_map(fn ($fix) => $fix->action, $inline->fixes));
        $this->assertSame('entry::c', $inline->fixes[0]->value);
        $this->assertSame('Link to Contact', $inline->fixes[0]->label->english());
        $this->assertSame([['value' => 'entry::c', 'title' => 'Contact', 'url' => '/contact']], $inline->meta['candidates']);
        $this->assertSame('The “Talk to us about Capacitor” link in Text: Copy doesn\'t go anywhere yet.', $inline->message()->english());
    }

    public function test_the_legacy_example_com_link_counts_while_1_x_lasts(): void
    {
        $context = $this->context(fn (array $values) => array_replace_recursive($values, ['page_builder' => [1 => ['button_link' => LinkDialect::PLACEHOLDER_URL, 'button_text' => LinkDialect::PLACEHOLDER_TEXT]]]));
        [$gap] = GapFinder::standard()->find($context)->ofKind(GapKind::LinkToChoose);

        $this->assertSame('link|page_builder/#c1/button_link||0', $gap->id);
        $this->assertTrue($gap->meta['legacy']);

        // A real link to example.com, with words of its own, is not a gap.
        $context = $this->context(fn (array $values) => array_replace_recursive($values, ['page_builder' => [1 => ['button_link' => LinkDialect::PLACEHOLDER_URL, 'button_text' => 'Our example']]]));
        $this->assertSame(['page_builder/#t1/copy'], array_map(fn (Gap $gap) => $gap->path->toString(), GapFinder::standard()->find($context)->ofKind(GapKind::LinkToChoose)));
    }

    public function test_links_to_entries_that_have_gone_are_broken(): void
    {
        $targets = new MemoryLinkTargets(['c' => ['title' => 'Contact']]);
        $context = $this->context(fn (array $values) => array_replace_recursive($values, [
            'related' => ['entry::gone'],
            'page_builder' => [1 => ['button_link' => 'entry::c'], 2 => ['copy' => '[Old](statamic://entry::gone) and [Contact](statamic://entry::c) and [Out](https://example.org).']],
        ]), targets: $targets);

        $broken = GapFinder::standard()->find($context)->ofKind(GapKind::LinkBroken);

        $this->assertSame(['link-broken|page_builder/#t1/copy|statamic://entry::gone|0', 'link-broken|related||0'], array_map(fn (Gap $gap) => $gap->id, $broken));
        $this->assertSame('Old', $broken[0]->meta['words']);
    }

    public function test_a_stock_preview_not_licensed_is_a_gap_and_a_licensed_one_is_not(): void
    {
        $stock = new StockImages(new InMemoryStockImageStore(Format::Statamic), new InMemoryLock, DomainOptions::statamic(), fn () => new DateTimeImmutable('2026-10-02'));
        $photo = new Photo('getty', '123', 'https://images.example.com/123.jpg', 'Kim/Getty Images', null, 'Royalty-free', 'Rocks', offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD)));
        $image = $stock->recordPreview($photo, new AssetRef('assets', 'stock/rocks.jpg'), 'comp-1', new DateTimeImmutable('2026-11-01'));
        $stock->recordFree(new Photo('unsplash', 'u1', 'https://images.example.com/u1.jpg', 'Ann', null, 'Unsplash'), new AssetRef('assets', 'stock/free.jpg'));

        $context = $this->context(fn (array $values) => ['featured_image' => ['assets::stock/rocks.jpg'], 'page_builder' => [['id' => 'h1', 'type' => 'hero', 'intro' => 'Words. ![Free](asset::assets::stock/free.jpg) ![Rocks](asset::assets::stock/rocks.jpg)']]] + $values, stock: $stock);

        $gaps = GapFinder::standard()->find($context)->ofKind(GapKind::StockPreview);

        $this->assertSame(['stock-preview|featured_image|getty|0', 'stock-preview|page_builder/#h1/intro|getty|0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame($image->id, $gaps[0]->meta['stockId']);
        $this->assertSame('preview', $gaps[0]->meta['state']);
        $this->assertFalse($gaps[0]->meta['expired']);
        $this->assertSame([FixAction::License, FixAction::ChooseAnother], array_map(fn ($fix) => $fix->action, $gaps[0]->fixes));
        $this->assertSame(FixCost::Licence, $gaps[0]->fixes[0]->cost);
        $this->assertSame($image->id, $gaps[0]->fixes[0]->value);
        $this->assertTrue($gaps[1]->meta['inline']);
    }

    public function test_a_fact_meant_for_a_number_field_comes_from_the_session_until_it_is_filled(): void
    {
        $built = (new EntryBuilder)->build(['page_builder' => [['type' => 'offer', 'price' => '[[ask: adult ticket price]]']]], $this->schema());
        $session = SessionGaps::fromDraft($built);
        $values = ['page_builder' => [['id' => 'o1', 'type' => 'offer']]];
        $context = fn (array $values) => new GapContext(schema: $this->schema(), entry: new EntryData($values), session: new SessionGaps(array_map(fn (array $entry) => ['path' => 'page_builder/#o1/price'] + $entry, $session->toArray())));

        [$gap] = GapFinder::standard()->find($context($values))->ofKind(GapKind::AskValue);

        $this->assertSame('ask-value|page_builder/#o1/price|adult ticket price|0', $gap->id);
        $this->assertSame(Severity::Required, $gap->severity, 'Not required, so counted rather than blocking.');
        $this->assertSame('Offer: Price is empty: adult ticket price. This one needs you.', $gap->message()->english());
        $this->assertTrue($gap->meta['fromDraft']);
        $this->assertSame('draft', $gap->meta['reason']);

        $values['page_builder'][0]['price'] = 12;
        $this->assertSame([], GapFinder::standard()->find($context($values))->ofKind(GapKind::AskValue));
    }

    public function test_an_empty_required_field_is_not_reported_twice(): void
    {
        $schema = new Schema([new Field('price', Kind::Number, 'Price', required: true), new Field('intro', Kind::Text, 'Intro', required: true)]);
        $context = new GapContext(schema: $schema, entry: new EntryData(['intro' => '']), session: new SessionGaps([['kind' => 'ask-value', 'path' => 'price', 'hint' => 'price']]));

        $gaps = GapFinder::standard()->find($context)->all();

        $this->assertSame(['ask-value|price|price|0', 'required|intro||0'], array_map(fn (Gap $gap) => $gap->id, $gaps));
        $this->assertSame(Severity::Blocks, $gaps[0]->severity, 'A fact for a required field blocks.');
        $this->assertSame([FixAction::WriteForMe, FixAction::Focus], array_map(fn ($fix) => $fix->action, $gaps[1]->fixes));
    }

    public function test_ids_stay_the_same_when_blocks_are_reordered(): void
    {
        $before = GapFinder::standard()->find($this->context());
        $after = GapFinder::standard()->find($this->context(fn (array $values) => ['page_builder' => array_reverse($values['page_builder'])] + $values));

        $ids = fn ($report) => array_map(fn (Gap $gap) => $gap->id, $report->all());

        $this->assertEqualsCanonicalizing($ids($before), $ids($after));
        $this->assertSame('page_builder.2.intro', $after->ofKind(GapKind::Ask)[0]->path->dotted(), 'Only the position changes.');
    }

    public function test_disabled_blocks_and_detectors_that_need_a_model_are_left_out(): void
    {
        $model = new class implements Detector
        {
            public int $ran = 0;

            public function kinds(): array
            {
                return [GapKind::OffStyleImage];
            }

            public function usesModel(): bool
            {
                return true;
            }

            public function detect(GapContext $context): iterable
            {
                $this->ran++;

                return [];
            }
        };

        $provider = new FakeProvider;
        $context = $this->context(fn (array $values) => array_replace_recursive($values, ['page_builder' => [0 => ['enabled' => false]]]));
        $report = GapFinder::standard()->with($model)->find($context);

        $this->assertSame([], $report->ofKind(GapKind::Ask));
        $this->assertSame(0, $model->ran);
        $provider->assertNothingSent();

        GapFinder::standard()->with($model)->find($context, withModel: true);
        $this->assertSame(1, $model->ran);
    }

    /**
     * @return iterable<string, array{RichTextDialect, Field, mixed}>
     */
    public static function shapes(): iterable
    {
        $text = 'Tickets cost [[ask: adult price]]. [Talk to us](#gw-link:contact-page) or [[item]], TBC.';
        $html = new HtmlDialect;
        $bard = new BardDialect;
        $rich = new Field('body', Kind::RichText, 'Body');
        $bardField = new Field('body', Kind::RichText, 'Body', type: 'bard');

        yield 'plain' => [$html, new Field('body', Kind::LongText, 'Body'), $text];
        yield 'html' => [$html, $rich, $html->fromMarkdown($text, $rich)];
        yield 'bard' => [$bard, $bardField, $bard->fromMarkdown($text, $bardField)];
        yield 'markdown' => [$html, new Field('body', Kind::LongText, 'Body', type: 'markdown'), $text];
    }

    #[DataProvider('shapes')]
    public function test_markers_are_found_in_each_shape_text_is_stored_in(RichTextDialect $dialect, Field $field, mixed $value): void
    {
        $report = GapFinder::standard()->find(new GapContext(schema: new Schema([$field]), entry: new EntryData(['body' => $value]), richText: $dialect));

        $this->assertSame(
            ['ask|body|adult price|0', 'link|body|contact-page|0', 'leftover-token|body|[[item]]|0', 'placeholder-text|body|tbc|0'],
            array_map(fn (Gap $gap) => $gap->id, $report->all()),
        );
    }

    public function test_the_report_is_ready_for_the_front_end(): void
    {
        $array = GapFinder::standard()->find($this->context())->toArray();

        $this->assertSame(6, $array['count']);
        $this->assertSame(2, $array['suggestions']);
        $this->assertSame(['id', 'kind', 'severity', 'path', 'dotted', 'field', 'label', 'hint', 'excerpt', 'occurrence', 'message', 'speech', 'fixes', 'meta'], array_keys($array['gaps'][0]));
        $this->assertSame(['action' => 'write-for-me', 'label' => ['key' => 'gaps.fix.write-for-me', 'params' => []], 'value' => null, 'cost' => 'model', 'primary' => true], $array['gaps'][0]['fixes'][0]);
        $this->assertNotFalse(json_encode($array));
    }

    public function test_every_message_key_core_uses_has_an_english_string(): void
    {
        foreach (GapKind::cases() as $kind) {
            $this->assertArrayHasKey($kind->value, Message::strings());
            $this->assertArrayHasKey('speech.'.$kind->value, Message::strings());
        }

        foreach (FixAction::cases() as $action) {
            $this->assertArrayHasKey('fix.'.$action->value, Message::strings());
        }

        $this->assertArrayHasKey('fix.add-link', Message::strings());
        $this->assertArrayHasKey('link-field', Message::strings());
        $this->assertSame('gaps.nope', (new Message('gaps.nope'))->english());
    }

    private function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('summary', Kind::LongText, 'Summary'),
            new Field('featured_image', Kind::Reference, 'Featured image', type: 'assets', files: true, meta: ['images' => true]),
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('eyebrow', Kind::Text, 'Eyebrow'), new Field('intro', Kind::RichText, 'Intro')]),
                'cta' => new Set('Call to action', '', [
                    new Field('heading', Kind::Text, 'Heading'),
                    new Field('button_link', Kind::Reference, 'Button link', type: 'link'),
                    new Field('button_text', Kind::Text, 'Button text'),
                ]),
                'text' => new Set('Text', '', [new Field('copy', Kind::RichText, 'Copy')]),
                'offer' => new Set('Offer', '', [new Field('price', Kind::Number, 'Price')]),
            ]),
            new Field('related', Kind::Reference, 'Related pages', required: true, type: 'entries'),
            new Field('seo_description', Kind::LongText, 'SEO description'),
        ]);
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change
     */
    private function context(?callable $change = null, ?MemoryLinkTargets $targets = null, ?StockImages $stock = null): GapContext
    {
        $values = [
            'title' => 'Capacitor App Development Based in the UK',
            'summary' => '',
            'featured_image' => ['assets::ghostwriter/'.Placeholders::FILENAME],
            'page_builder' => [
                ['id' => 'h1', 'type' => 'hero', 'eyebrow' => 'Capacitor', 'intro' => "Capacitor wraps the web app you already have in a native shell. It ships to the App Store and Google Play without a rebuild in [[ask: how long a typical Capacitor project takes]].\n\nNo second codebase."],
                ['id' => 'c1', 'type' => 'cta', 'heading' => 'Have a web product that should be an app?', 'button_link' => '#gw-link:button-link', 'button_text' => LinkDialect::PLACEHOLDER_TEXT],
                ['id' => 't1', 'type' => 'text', 'copy' => '[Talk to us about Capacitor](#gw-link:contact-page). Every [[item]] counts.'],
            ],
            'related' => [],
            'seo_description' => 'Price TBC.',
        ];

        return new GapContext(
            schema: $this->schema(),
            entry: new EntryData($change !== null ? $change($values) : $values, 'cap'),
            richText: new MarkdownAsStored,
            links: new StatamicLinks,
            placeholders: new MemoryAssets,
            assets: new MemoryAssets,
            targets: $targets,
            stock: $stock,
            pattern: new Pattern(filled: ['summary' => 0.9, 'featured_image' => 1.0, 'related' => 0.8, 'hero.eyebrow' => 1.0]),
        );
    }
}
