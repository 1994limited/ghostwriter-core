<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkPick;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkValidator;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LinkValidator: one case per rule of SEO layer §7.3 (and §7.2's spread
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

    public function test_spread_duplicates_markers_and_the_limit(): void
    {
        $this->assertSame([
            'u3: we cut back only' => 'spread',
            'u4: first walk round' => 'duplicate',
        ], self::rules([
            new LinkPick('u3', 'planting plan we drew for you', 'e1'),
            new LinkPick('u3', 'we cut back only', 'e3'),
            new LinkPick('u4', 'first walk round', 'e1'),
        ]));

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
}
