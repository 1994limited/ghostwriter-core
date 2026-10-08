<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkPick;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkValidator;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkVerdicts;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoRequest;
use NineteenNinetyFour\Ghostwriter\Core\Seo\ValidatedLinks;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LinkValidator: one case per rule of SEO layer §7.3 (and §7.2's spread,
 * one link a paragraph and two a section,
 * and limits), each dropped with its rule, and the good ones kept.
 */
final class LinkValidatorTest extends TestCase
{
    private const LEAD = 'Winter is when a garden is set up for the year. A planting plan helps.';

    private const CARE = "## Cutting back\n\nWe cut back only what has finished. If you have a planting plan we drew for you, we follow it. **Seed heads stay standing** for the birds. A garden plan, then a garden plan again.\n\n> Our winter care visits are the best thing we bought.\n\nSee our [design page](statamic://entry::design) and [[ask: the price of a visit]] for more.";

    private const BOOK = "## Booking a visit\n\nIf you would like a winter visit, tell us about your garden and we will arrange a first walk round. Click here to book.";

    /**
     * @return array<string, Unit>
     */
    private static function units(): array
    {
        return [
            'u2' => new Unit('u2', UnitKind::Prose, FieldPath::of('body'), self::LEAD, part: 0),
            'u3' => new Unit('u3', UnitKind::Section, FieldPath::of('body'), self::CARE, part: 1),
            'u4' => new Unit('u4', UnitKind::Section, FieldPath::of('body'), self::BOOK, part: 2),
            'u5' => new Unit('u5', UnitKind::Quote, FieldPath::of('body'), '> A quotation about winter care visits.', part: 3),
        ];
    }

    private static function request(int $room = 5, string $title = 'Winter garden care'): SeoRequest
    {
        return new SeoRequest($title, array_values(self::units()), ['u2', 'u3', 'u4', 'u5'], [
            new DigestEntry(new EntryRef('services', 'plans'), 'Planting plans', '/garden-services/planting-plans', 'A plan for every border.', 'entry::plans', 'Garden services'),
            new DigestEntry(new EntryRef('pages', 'contact'), 'Contact us', '/contact', 'Book a first visit.', 'entry::contact', 'Pages'),
            new DigestEntry(new EntryRef('journal', 'october'), 'What to do in the garden in October', '/journal/october', 'Seed heads and bulbs.', 'entry::october', 'Journal'),
            new DigestEntry(null, 'An address only', null, '', null, 'Pages'),
        ], $room);
    }

    public function test_the_first_candidates_show_their_whole_summary_and_the_rest_a_short_one(): void
    {
        $summary = 'A planting plan for every border: what to grow, where, and how to keep it looking right through the year, season by season.';
        $candidates = array_map(fn (int $i) => new DigestEntry(new EntryRef('journal', "p{$i}"), "Page {$i}", "/p{$i}", $summary, "entry::p{$i}", 'Journal'), range(1, SeoRequest::FULL_SUMMARIES + 2));
        $prompt = (new SeoRequest('Winter garden care', array_values(self::units()), ['u2'], $candidates, 2))->prompt();

        $this->assertStringContainsString("e10. Page 10 (Journal) · /p10\n    {$summary}", $prompt);
        $this->assertStringContainsString("e11. Page 11 (Journal) · /p11\n    A planting plan for every border: what to grow, where, and how to keep it…\n", $prompt);
    }

    /**
     * @param  list<LinkPick>  $picks
     * @return array<string, string>
     */
    private static function rules(array $picks, int $room = 5, string $locale = 'en', string $title = 'Winter garden care'): array
    {
        return (new LinkValidator)->validate($picks, self::request($room, $title), self::units(), new StatamicLinks, 'u2', $locale)->rules();
    }

    /**
     * @return array<string, array{0: LinkPick, 1: string}>
     */
    public static function broken(): array
    {
        return [
            'a unit that may take no link' => [new LinkPick('u1', 'Winter garden care', 'e1'), 'unit'],
            'a quotation unit' => [new LinkPick('u5', 'winter care visits', 'e1'), 'unit'],
            'a target not shown' => [new LinkPick('u3', 'planting plan we drew for you', 'e9'), 'target'],
            'a target with no way to link to it' => [new LinkPick('u3', 'planting plan we drew for you', 'e4'), 'not-linkable'],
            'one word' => [new LinkPick('u3', 'birds', 'e1'), 'length'],
            'more than eight words' => [new LinkPick('u3', 'If you have a planting plan we drew for you', 'e1'), 'length'],
            'only stop words' => [new LinkPick('u4', 'If you would', 'e2'), 'stop-words'],
            'vague' => [new LinkPick('u4', 'Click here', 'e2'), 'vague'],
            'vague, with more words' => [new LinkPick('u4', 'Click here to book', 'e2'), 'vague'],
            'the page\'s own title' => [new LinkPick('u2', 'Winter garden care', 'e1'), 'own-title'],
            'words not there' => [new LinkPick('u3', 'planting scheme we drew', 'e1'), 'not-found'],
            'words there twice, no prefix' => [new LinkPick('u3', 'garden plan', 'e1'), 'ambiguous'],
            'in a heading' => [new LinkPick('u3', 'Cutting back', 'e1'), 'unsafe-place'],
            'in bold' => [new LinkPick('u3', 'Seed heads stay standing', 'e3'), 'unsafe-place'],
            'in a quotation' => [new LinkPick('u3', 'winter care visits', 'e1'), 'unsafe-place'],
            'in an existing link' => [new LinkPick('u3', 'design page', 'e1'), 'unsafe-place'],
            'in a marker' => [new LinkPick('u3', 'price of a visit', 'e1'), 'unsafe-place'],
            'across two sentences' => [new LinkPick('u3', 'the birds. A garden', 'e1'), 'crosses-sentence'],
            'in the page\'s first sentence' => [new LinkPick('u2', 'set up for the year', 'e1'), 'first-sentence'],
            'a marker with no hint' => [new LinkPick('u4', 'tell us about your garden'), 'marker-hint'],
        ];
    }

    #[DataProvider('broken')]
    public function test_a_pick_that_breaks_a_rule_is_dropped(LinkPick $pick, string $rule): void
    {
        $this->assertSame(["{$pick->unit}: {$pick->exact}" => $rule], self::rules([$pick]));
    }

    public function test_good_picks_are_kept_with_where_their_words_are(): void
    {
        $validated = (new LinkValidator)->validate([
            new LinkPick('u3', 'planting plan we drew for you', 'e1', why: 'Follows a plan.'),
            new LinkPick('u4', 'tell us about your garden', 'e2'),
        ], self::request(), self::units(), new StatamicLinks, 'u2');

        $this->assertSame([], $validated->dropped);
        $this->assertSame(['l1', 'l2'], array_map(fn ($link) => $link->id, $validated->kept));
        $this->assertSame(['statamic://entry::plans', 'statamic://entry::contact'], array_map(fn ($link) => $link->href, $validated->kept));
        $this->assertSame('planting plan we drew for you', $validated->kept[0]->words());
        $this->assertStringContainsString('If you have a [planting plan we drew for you](statamic://entry::plans), we follow it.', $validated->kept[0]->linked());
        $this->assertSame('We cut back only what has finished. If you have a ⟦planting plan we drew for you⟧, we follow it. **Seed heads stay standing** for the birds. A garden plan, then a garden plan again.', $validated->kept[0]->paragraph());
        $this->assertSame('Cutting back', $validated->kept[0]->heading());
    }

    public function test_repeated_words_are_told_apart_by_the_prefix(): void
    {
        $validated = (new LinkValidator)->validate([new LinkPick('u3', 'garden plan', 'e1', 'then a ')], self::request(), self::units(), new StatamicLinks, 'u2');

        $this->assertCount(1, $validated->kept);
        $this->assertStringContainsString('A garden plan, then a [garden plan](statamic://entry::plans) again.', $validated->kept[0]->linked());
    }

    private const MEADOW = "## Meadows\n\nA meadow is cut once a year, in late summer. We leave the seedheads standing for the birds.\n\nOur meadow services cover sowing and the first cut.\n\n- Sow wildflower seed in autumn.\n- Rake off the cuttings each year.\n\nTell us about your meadow and we will visit.";

    private const LINKED = "## Care\n\nSee our [design page](statamic://entry::design) for the planting we do ourselves.\n\nWe follow the planting plan we drew for you.\n\nTell us about your garden today.";

    /**
     * @param  list<LinkPick>  $picks
     * @return array{kept: list<string>, dropped: array<string, string>}
     */
    private static function spread(array $picks, string $markdown = self::MEADOW): array
    {
        $units = ['u6' => new Unit('u6', UnitKind::Section, FieldPath::of('body'), $markdown, part: 4)];
        $validated = (new LinkValidator)->validate($picks, self::request(), $units, new StatamicLinks, 'u2');

        return ['kept' => array_map(fn ($link) => $link->words(), $validated->kept), 'dropped' => $validated->rules()];
    }

    public function test_never_two_links_in_one_paragraph(): void
    {
        $this->assertSame(['kept' => ['leave the seedheads standing'], 'dropped' => ['u6: meadow is cut once a year' => 'paragraph']], self::spread([
            new LinkPick('u6', 'leave the seedheads standing', 'e3'),
            new LinkPick('u6', 'meadow is cut once a year', 'e1'),
        ]));
    }

    public function test_two_links_in_a_section_in_different_paragraphs_and_never_a_third(): void
    {
        $this->assertSame(['kept' => ['leave the seedheads standing', 'Our meadow services'], 'dropped' => ['u6: Tell us about your meadow' => 'spread']], self::spread([
            new LinkPick('u6', 'leave the seedheads standing', 'e3'),
            new LinkPick('u6', 'Our meadow services', 'e1'),
            new LinkPick('u6', 'Tell us about your meadow', 'e2'),
        ]));
    }

    public function test_each_list_item_is_its_own_paragraph(): void
    {
        $this->assertSame(['kept' => ['Sow wildflower seed', 'Rake off the cuttings'], 'dropped' => []], self::spread([
            new LinkPick('u6', 'Sow wildflower seed', 'e3'),
            new LinkPick('u6', 'Rake off the cuttings', 'e1'),
        ]));
    }

    public function test_each_item_of_a_list_value_is_its_own_paragraph(): void
    {
        $units = ['u7' => new Unit('u7', UnitKind::List, FieldPath::of('steps'), "Sow wildflower seed in autumn\nRake off the cuttings each year")];
        $validated = (new LinkValidator)->validate([
            new LinkPick('u7', 'Sow wildflower seed', 'e3'),
            new LinkPick('u7', 'Rake off the cuttings', 'e1'),
        ], self::request(), $units, new StatamicLinks, 'u2');

        $this->assertSame([], $validated->rules());
        $this->assertCount(2, $validated->kept);
    }

    public function test_links_already_there_count_towards_the_spread(): void
    {
        $this->assertSame(['kept' => ['planting plan we drew for you'], 'dropped' => [
            'u6: the planting we do ourselves' => 'paragraph',
            'u6: Tell us about your garden' => 'spread',
        ]], self::spread([
            new LinkPick('u6', 'the planting we do ourselves', 'e1'),
            new LinkPick('u6', 'planting plan we drew for you', 'e1'),
            new LinkPick('u6', 'Tell us about your garden', 'e2'),
        ], self::LINKED));
    }

    public function test_spread_duplicates_markers_and_the_limit(): void
    {
        $this->assertSame([
            'u3: we cut back only' => 'spread',
            'u4: first walk round' => 'duplicate',
        ], self::rules([
            new LinkPick('u3', 'planting plan we drew for you', 'e1'),
            new LinkPick('u3', 'we cut back only', 'e3'),
            new LinkPick('u4', 'first walk round', 'e1'),
        ]), 'u3 has a link already: one more makes two.');

        $validated = (new LinkValidator)->validate([
            new LinkPick('u4', 'tell us about your garden', '', hint: 'booking page'),
            new LinkPick('u3', 'planting plan we drew for you', '', hint: 'planting plans'),
        ], self::request(), self::units(), new StatamicLinks, 'u2');
        $this->assertSame(['#gw-link:booking-page'], array_map(fn ($link) => $link->href, $validated->kept), 'One marker at most.');
        $this->assertSame('marker-limit', $validated->dropped[0]['rule']);
        $this->assertNull($validated->kept[0]->target);

        $this->assertSame(['u4: tell us about your garden' => 'limit'], self::rules([
            new LinkPick('u3', 'planting plan we drew for you', 'e1'),
            new LinkPick('u4', 'tell us about your garden', 'e2'),
        ], room: 1));
    }

    public function test_a_long_target_title_pasted_whole_reads_as_stuffing(): void
    {
        $units = ['u4' => new Unit('u4', UnitKind::Section, FieldPath::of('body'), "## More\n\nSee what to do in the garden in October for the autumn jobs, or contact us today.", part: 2)];
        $validated = (new LinkValidator)->validate([
            new LinkPick('u4', 'what to do in the garden in October', 'e3'),
        ], self::request(), $units, new StatamicLinks);

        $this->assertSame('whole-title', $validated->dropped[0]['rule'] ?? null);

        $short = (new LinkValidator)->validate([new LinkPick('u4', 'contact us today', 'e2')], self::request(), $units, new StatamicLinks);
        $this->assertCount(1, $short->kept, 'A short title in the words is fine.');
    }

    public function test_vague_words_in_each_language(): void
    {
        $units = ['u4' => new Unit('u4', UnitKind::Section, FieldPath::of('body'), "## Mehr\n\nHier klicken für mehr. Cliquez ici pour réserver. Klik hier voor meer. Haz clic aquí para reservar. Weiterlesen bitte.", part: 2)];

        foreach (['Hier klicken' => 'de', 'Cliquez ici' => 'fr', 'Klik hier' => 'nl', 'Haz clic aquí' => 'es'] as $words => $locale) {
            $validated = (new LinkValidator)->validate([new LinkPick('u4', $words, 'e2')], self::request(), $units, new StatamicLinks, null, $locale);
            $this->assertSame('vague', $validated->dropped[0]['rule'] ?? null, "{$words} ({$locale})");
        }
    }

    public function test_no_dialect_no_links(): void
    {
        $this->assertSame('not-linkable', (new LinkValidator)->validate([new LinkPick('u3', 'planting plan we drew for you', 'e1')], self::request(), self::units(), new NoLinks)->dropped[0]['rule']);
    }

    public function test_the_verdicts_are_read_as_keep_keep_with_anchor_or_drop(): void
    {
        $verdicts = LinkVerdicts::fromArray([
            ['notes' => '…', 'id' => 'l1', 'verdict' => 'keep', 'anchor' => '', 'reason' => 'Fits.'],
            ['notes' => '…', 'id' => 'l2', 'verdict' => 'keep-with-anchor', 'anchor' => ' our planting plans ', 'reason' => 'Better words.'],
            ['notes' => '…', 'id' => 'l3', 'verdict' => 'drop', 'anchor' => '', 'reason' => 'Wrong page.'],
            ['notes' => '…', 'id' => 'l4', 'verdict' => 'keep-with-anchor', 'anchor' => '', 'reason' => 'No words given.'],
            ['notes' => '…', 'id' => 'l5', 'verdict' => 'keep', 'anchor' => 'ignored words', 'reason' => 'Fits.'],
            ['id' => 'l6', 'verdict' => 'drop'],
            'not a verdict',
            ['verdict' => 'drop', 'reason' => 'No id.'],
        ]);

        $this->assertSame(['l3' => 'Wrong page.', 'l6' => ''], $verdicts->drop);
        $this->assertSame(['l2' => ['anchor' => 'our planting plans', 'why' => 'Better words.']], $verdicts->anchors, 'Only keep-with-anchor with words moves a link.');
        $this->assertTrue($verdicts->drops('l3'));
        $this->assertFalse($verdicts->drops('l1'));
    }

    private static function kept(): ValidatedLinks
    {
        return (new LinkValidator)->validate([
            new LinkPick('u3', 'we drew for you', 'e1', why: 'Follows a plan.'),
            new LinkPick('u4', 'tell us about your garden', 'e2'),
        ], self::request(), self::units(), new StatamicLinks, 'u2');
    }

    public function test_the_verifier_drops_only_what_it_drops_and_keeps_the_rest_as_they_were(): void
    {
        $judged = (new LinkValidator)->judged(self::kept(), new LinkVerdicts(['l2' => 'Not the contact page.']), self::request(), 'u2');

        $this->assertSame(['l1'], array_map(fn ($link) => $link->id, $judged->kept));
        $this->assertSame('we drew for you', $judged->kept[0]->words());
        $this->assertSame(['u4: tell us about your garden' => 'verifier: Not the contact page.'], $judged->rules());
        $this->assertSame([], $judged->anchored);
    }

    public function test_better_words_from_the_same_sentence_move_the_link_to_the_same_page(): void
    {
        $verdicts = new LinkVerdicts(anchors: ['l1' => ['anchor' => 'a planting plan', 'why' => 'Names the page.']]);
        $judged = (new LinkValidator)->judged(self::kept(), $verdicts, self::request(), 'u2');

        $this->assertCount(2, $judged->kept, 'Nothing is dropped for its words.');
        $link = $judged->kept[0];
        $this->assertSame(['l1', 'a planting plan', 'statamic://entry::plans', 'Planting plans'], [$link->id, $link->words(), $link->href, $link->target?->title]);
        $this->assertSame('a planting plan', $link->pick->exact);
        $this->assertSame('Follows a plan.', $link->pick->why);
        $this->assertStringContainsString('If you have [a planting plan](statamic://entry::plans) we drew for you, we follow it.', $link->linked());
        $this->assertSame(['we drew for you → a planting plan' => 'taken'], $judged->anchors());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badAnchors(): array
    {
        return [
            'not in the unit' => ['a planting scheme', 'not-found'],
            'twice in the unit' => ['garden plan', 'ambiguous'],
            'another sentence of the unit' => ['We cut back only', 'other-sentence'],
            'vague' => ['click here', 'vague'],
            'one word' => ['plan', 'length'],
            'only stop words' => ['if you have', 'stop-words'],
            'bold' => ['Seed heads stay standing', 'unsafe-place'],
            'a heading' => ['Cutting back', 'unsafe-place'],
            'an existing link' => ['our design page', 'not-found'],
            'across two sentences' => ['the birds. A garden', 'crosses-sentence'],
        ];
    }

    #[DataProvider('badAnchors')]
    public function test_better_words_that_break_a_rule_leave_the_link_on_its_first_words(string $anchor, string $rule): void
    {
        $verdicts = new LinkVerdicts(anchors: ['l1' => ['anchor' => $anchor, 'why' => '…']]);
        $judged = (new LinkValidator)->judged(self::kept(), $verdicts, self::request(), 'u2');

        $this->assertCount(2, $judged->kept, 'The page was judged right, and the first words passed every check.');
        $this->assertSame('we drew for you', $judged->kept[0]->words());
        $this->assertSame(["we drew for you → {$anchor}" => $rule], $judged->anchors());
    }

    public function test_better_words_in_the_first_sentence_or_the_same_words_change_nothing(): void
    {
        $units = self::units();
        $kept = (new LinkValidator)->validate([new LinkPick('u2', 'A planting plan helps', 'e1')], self::request(), $units, new StatamicLinks, 'u2');
        $this->assertCount(1, $kept->kept);

        $first = (new LinkValidator)->judged($kept, new LinkVerdicts(anchors: ['l1' => ['anchor' => 'a garden is set up', 'why' => '…']]), self::request(), 'u2');
        $this->assertSame('A planting plan helps', $first->kept[0]->words());
        $this->assertSame('first-sentence', $first->anchored[0]['rule'], 'The page\'s first sentence takes no link.');

        $same = (new LinkValidator)->judged($kept, new LinkVerdicts(anchors: ['l1' => ['anchor' => 'a planting plan helps', 'why' => '…']]), self::request(), 'u2');
        $this->assertSame([], $same->anchored);
    }

    public function test_the_page_title_as_better_words_is_refused(): void
    {
        $units = ['u4' => new Unit('u4', UnitKind::Section, FieldPath::of('body'), '## More

Read about winter garden care with our team in the north.', part: 2)];
        $kept = (new LinkValidator)->validate([new LinkPick('u4', 'with our team in the north', 'e2')], self::request(), $units, new StatamicLinks);

        $judged = (new LinkValidator)->judged($kept, new LinkVerdicts(anchors: ['l1' => ['anchor' => 'winter garden care', 'why' => '…']]), self::request());
        $this->assertSame('with our team in the north', $judged->kept[0]->words());
        $this->assertSame('own-title', $judged->anchored[0]['rule']);
    }
}
