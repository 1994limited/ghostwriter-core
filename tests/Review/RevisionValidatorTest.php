<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Review;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comment;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionItem;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionReply;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Review\Verdict;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use PHPUnit\Framework\TestCase;

/** One case per RevisionValidator rule (§6.4), with no model. */
final class RevisionValidatorTest extends TestCase
{
    private const BRIEF = ['Winter care: four visits between November and February.'];

    private const SECTION = "## The visits\n\n**November: Cut back.** Prune the shrubs that need it.\n\n**January: Feed.** Mulch the beds and [[ask: what else in January]].";

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function verdict(Scope $scope, string $comment, ?RevisionItem $item, ?array $data = null, ?Extras $extras = null): Verdict
    {
        $data ??= Northfold::blocksDraft();

        return (new RevisionValidator(Northfold::blocks()))->check(Comment::make(1, $scope, $comment, 1), $item, $data, Units::fromDraft($data, Northfold::blocks()), $extras ?? Northfold::extras(), self::BRIEF, []);
    }

    /**
     * @param  array<string, string>  $units
     * @param  list<array{unit: string, exact: string, with: string}>  $replace
     * @param  array<string, array{text: string, parts: array<string, string>}|null>  $extras
     */
    private static function item(array $units = [], array $replace = [], array $extras = []): RevisionItem
    {
        return new RevisionItem(1, 'Done.', $units, $replace, null, $extras);
    }

    public function test_a_change_within_scope_passes_with_the_new_data(): void
    {
        $verdict = $this->verdict(Scope::block(['u7']), 'Shorter.', self::item(['u7' => str_replace('Prune the shrubs that need it.', 'Prune what needs it.', self::SECTION)]));

        $this->assertTrue($verdict->passes(), implode(', ', $verdict->rules));
        $this->assertStringContainsString('Prune what needs it.', (string) $verdict->data['page_builder'][1]['body']);
        $this->assertStringContainsString('## Who it suits', (string) $verdict->data['page_builder'][1]['body'], 'the other sections stay');
    }

    public function test_scope_a_unit_outside_the_comment(): void
    {
        $verdict = $this->verdict(Scope::block(['u7']), 'Shorter.', self::item(['u6' => 'Winter sets a garden up for the year.']));

        $this->assertSame([RevisionValidator::SCOPE], $verdict->rules);
    }

    public function test_scope_exact_words_not_in_the_unit_once(): void
    {
        $missing = $this->verdict(Scope::block(['u7']), 'Shorter.', self::item(replace: [['unit' => 'u7', 'exact' => 'Prune the roses', 'with' => 'Prune']]));
        $twice = $this->verdict(Scope::block(['u7']), 'Shorter.', self::item(replace: [['unit' => 'u7', 'exact' => '**', 'with' => '_']]));

        $this->assertSame([RevisionValidator::SCOPE], $missing->rules);
        $this->assertSame([RevisionValidator::SCOPE], $twice->rules);
    }

    public function test_text_range_a_comment_on_words_changes_only_their_sentences(): void
    {
        $scope = Scope::text('u7', new TextQuote('Prune the shrubs that need it.'));
        $inside = $this->verdict($scope, 'Simpler.', self::item(['u7' => str_replace('Prune the shrubs that need it.', 'Prune what needs it.', self::SECTION)]));
        $outside = $this->verdict($scope, 'Simpler.', self::item(['u7' => str_replace(['Prune the shrubs that need it.', 'Mulch the beds'], ['Prune what needs it.', 'Mulch every bed'], self::SECTION)]));

        $this->assertTrue($inside->passes(), implode(', ', $inside->rules));
        $this->assertSame([RevisionValidator::TEXT_RANGE], $outside->rules);
    }

    public function test_markers_an_ask_or_a_link_lost_or_made_up(): void
    {
        $lostAsk = $this->verdict(Scope::block(['u7']), 'Shorter.', self::item(['u7' => str_replace(' and [[ask: what else in January]]', '', self::SECTION)]));
        $u8 = Units::fromDraft(Northfold::blocksDraft(), Northfold::blocks())->get('u8')?->markdown ?? '';
        $lostLink = $this->verdict(Scope::block(['u8']), 'Shorter.', self::item(['u8' => str_replace(' [Talk to us](#gw-link:contact-page)', ' Talk to us.', $u8)]));
        $newAsk = $this->verdict(Scope::block(['u9']), 'Add the price.', self::item(['u9' => 'Book a winter visit from [[ask: price]]']));

        $this->assertSame([RevisionValidator::MARKERS], $lostAsk->rules);
        $this->assertSame([RevisionValidator::MARKERS], $lostLink->rules);
        $this->assertSame([RevisionValidator::MARKERS], $newAsk->rules);
    }

    public function test_markers_an_ask_filled_from_the_comment_passes_and_says_so(): void
    {
        $verdict = $this->verdict(Scope::block(['u7']), 'In January we also cut back the grasses.', self::item(replace: [['unit' => 'u7', 'exact' => '[[ask: what else in January]]', 'with' => 'cut back the grasses']]));

        $this->assertTrue($verdict->passes(), implode(', ', $verdict->rules));
        $this->assertSame(['u7' => [['ask' => 'what else in January', 'value' => 'cut back the grasses', 'by' => 1]]], $verdict->filled);
    }

    public function test_link_to_another_site(): void
    {
        $verdict = $this->verdict(Scope::block(['u10']), 'Link it.', self::item(['u10' => 'Book now at https://example.com/book']));

        $this->assertContains(RevisionValidator::LINK, $verdict->rules);
    }

    public function test_facts_a_figure_nobody_gave(): void
    {
        $invented = $this->verdict(Scope::block(['u9']), 'Add the price.', self::item(['u9' => 'Book a winter visit from £75']));
        $given = $this->verdict(Scope::block(['u9']), 'Add the price: £60 a visit.', self::item(['u9' => 'Book a winter visit from £60']));
        $brief = $this->verdict(Scope::block(['u9']), 'Say how many visits.', self::item(['u9' => 'Book four winter visits']));

        $this->assertSame([RevisionValidator::FACTS], $invented->rules);
        $this->assertSame(['£75'], $invented->unsourced);
        $this->assertTrue($given->passes(), 'a comment is a source');
        $this->assertTrue($brief->passes(), 'so is the brief');
    }

    public function test_lost_a_section_merged_into_the_one_before(): void
    {
        $verdict = $this->verdict(Scope::block(['u7']), 'No heading here.', self::item(['u7' => str_replace("## The visits\n\n", '', self::SECTION)]));

        $this->assertSame([RevisionValidator::LOST], $verdict->rules);
    }

    public function test_lost_a_unit_emptied(): void
    {
        $verdict = $this->verdict(Scope::block(['u4']), 'Drop it.', self::item(['u4' => '']));

        $this->assertContains(RevisionValidator::LOST, $verdict->rules);
    }

    public function test_shape_an_image_or_a_row_with_the_wrong_fields(): void
    {
        $image = $this->verdict(Scope::block(['u5']), 'A brighter photo.', self::item(['u5' => 'A brighter photo']));
        $data = Northfold::blocksDraft();
        $data['page_builder'][] = ['type' => 'faq', 'questions' => [['question' => 'How often?', 'answer' => 'Four times a winter.']]];
        $row = Units::fromDraft($data, Northfold::blocks())->all();
        $rowId = end($row)->id;
        $wrong = $this->verdict(Scope::block([$rowId]), 'Simpler.', self::item([$rowId => 'How often do you come? Four times a winter.']), $data);
        $right = $this->verdict(Scope::block([$rowId]), 'Simpler.', self::item([$rowId => "How often do you come?\n\nFour times a winter."]), $data);

        $this->assertSame([RevisionValidator::SHAPE], $image->rules);
        $this->assertSame([RevisionValidator::SHAPE], $wrong->rules);
        $this->assertTrue($right->passes(), implode(', ', $right->rules));
        $this->assertSame(['question' => 'How often do you come?', 'answer' => 'Four times a winter.'], $right->data['page_builder'][4]['questions'][0]);
    }

    public function test_missing_no_item_for_the_comment(): void
    {
        $this->assertSame([RevisionValidator::MISSING], $this->verdict(Scope::page(), 'Warmer.', null)->rules);
    }

    public function test_size_is_a_warning_unless_the_comment_asked_for_length(): void
    {
        $short = str_replace(["**November: Cut back.** Prune the shrubs that need it.\n\n", '**January: Feed.** '], ['', ''], self::SECTION);
        $unasked = $this->verdict(Scope::block(['u7']), 'Plainer.', self::item(['u7' => $short]));
        $asked = $this->verdict(Scope::block(['u7']), 'Much shorter, please.', self::item(['u7' => $short]));

        $this->assertTrue($unasked->passes());
        $this->assertSame([RevisionValidator::SIZE], $unasked->warnings);
        $this->assertSame([], $asked->warnings);
    }

    public function test_extras_in_scope_are_checked_like_text(): void
    {
        $sourced = $this->verdict(Scope::block(['x1.1']), 'Say four.', self::item(extras: ['x1.1' => ['text' => 'Four visits each winter', 'parts' => []]]));
        $invented = $this->verdict(Scope::block(['x1.1']), 'Bigger.', self::item(extras: ['x1.1' => ['text' => '9 visits a winter', 'parts' => []]]));
        $outside = $this->verdict(Scope::block(['u9']), 'Bigger.', self::item(extras: ['x1.1' => null]));
        $filled = $this->verdict(Scope::block(['x1.2']), 'It is £60 a visit.', self::item(extras: ['x1.2' => ['text' => 'From £60 a visit', 'parts' => []]]));

        $this->assertTrue($sourced->passes(), implode(', ', $sourced->rules));
        $this->assertSame([RevisionValidator::FACTS], $invented->rules);
        $this->assertSame([RevisionValidator::SCOPE], $outside->rules);
        $this->assertTrue($filled->passes(), implode(', ', $filled->rules));
        $this->assertSame([['ask' => 'price per visit', 'value' => '£60', 'by' => 1]], $filled->filled['x1.2'] ?? null);
    }

    public function test_layout_a_new_arrangement_passes_the_layout_rules_or_is_refused(): void
    {
        $data = Northfold::blocksDraft();
        $units = Units::fromDraft($data, Northfold::blocks());
        $writer = Plans::fromDraft($data, $units, Northfold::blocks());
        $validator = new RevisionValidator(Northfold::blocks());
        $scope = Scope::block(['u6', 'u7', 'u8']);
        $cards = [
            ['type' => 'text', 'place' => ['body' => 'u6']],
            ['type' => 'section', 'place' => ['heading' => 'u7#1'], 'children' => [
                ['type' => 'card', 'place' => ['heading' => 'u7#2:lead', 'body' => 'u7#2:rest']],
                ['type' => 'card', 'place' => ['heading' => 'u7#3:lead', 'body' => 'u7#3:rest']],
            ]],
            ['type' => 'text', 'place' => ['body' => 'u8']],
        ];

        $plan = $validator->layout($writer, $cards, $scope, $units, Extras::fromArray([]), $data);
        $this->assertSame(['hero', 'text', 'section', 'text', 'spacer', 'cta'], $plan?->sequences()['page_builder'] ?? null);

        $why = null;
        $this->assertNull($validator->layout($writer, [['type' => 'carousel', 'place' => ['slides' => ['u6', 'u7', 'u8']]]], $scope, $units, Extras::fromArray([]), $data, null, $why));
        $this->assertNotNull($why);

        $why = null;
        $this->assertNull($validator->layout($writer, [['type' => 'text', 'place' => ['body' => ['u6', 'u7']]]], $scope, $units, Extras::fromArray([]), $data, null, $why), 'u8 would be lost');
        $this->assertStringContainsString('u8', (string) $why);
    }

    public function test_the_reply_is_read_leniently(): void
    {
        $reply = RevisionReply::read("Here you go.\n<changes>\n```yaml\n- comment: 2\n  reply: Done.\n  units: { u7: \"x\" }\n  replace: [{ unit: u8, exact: a, with: b }, { unit: u8 }]\n  extras: { x1.1: null, x1.2: \"From £60\" }\n- not an item\n```\n</changes>");

        $this->assertSame('', $reply->problem);
        $this->assertSame(['u7' => 'x'], $reply->item(2)?->units);
        $this->assertSame([['unit' => 'u8', 'exact' => 'a', 'with' => 'b']], $reply->item(2)?->replace);
        $this->assertSame(['x1.1' => null, 'x1.2' => ['text' => 'From £60', 'parts' => []]], $reply->item(2)?->extras);
        $this->assertNull($reply->item(1));
        $this->assertSame('there was no <changes> block', RevisionReply::read('Sorry.')->problem);
    }
}
