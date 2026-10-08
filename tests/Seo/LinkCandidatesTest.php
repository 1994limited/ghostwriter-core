<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RowKind;
use PHPUnit\Framework\TestCase;

final class LinkCandidatesTest extends TestCase
{
    private const DRAFT = "Pruning fruit trees in winter\n\n## When to prune\n\nWinter pruning keeps apple and pear trees healthy and productive.";

    private const MEADOWS = "Meadows are not low-maintenance\n\n## Why a meadow still needs work\n\nA wildflower meadow is cut once a year, after the seed has set and dropped. Leave it and the grasses take over.";

    public function test_stems_drop_stop_words_short_words_and_numbers_and_are_stemmed_in_the_language(): void
    {
        $this->assertSame(['prune', 'fruit', 'tree', 'winter'], LinkCandidates::stems('Pruning the fruit trees in 2026 winter', 'en'));
        $this->assertSame(['gart', 'planung'], LinkCandidates::stems('Der Garten und die Planung', 'de'));
        $this->assertSame(['jardin', 'plant'], LinkCandidates::stems('Les jardins et les plantes', 'fr'));
        $this->assertSame(['tuin', 'bloem'], LinkCandidates::stems('De tuinen en de bloemen', 'nl'));
        $this->assertSame(['jardin', 'flor'], LinkCandidates::stems('Los jardines y las flores', 'es'));
        $this->assertContains('the', LinkCandidates::stems('the garden'), 'No language: no stop words.');
        $this->assertSame(['garde'], LinkCandidates::stems('gardens'), 'No language: the first five letters.');
    }

    public function test_draft_stems_read_the_title_headings_and_first_200_words(): void
    {
        $body = str_repeat('filler ', 200).'zebra';
        $stems = LinkCandidates::draftStems("Title words\n\n## A heading\n\n{$body}", 'en');

        $this->assertContains('titl', $stems);
        $this->assertContains('head', $stems);
        $this->assertContains('filler', $stems);
        $this->assertNotContains('zebra', $stems);
    }

    public function test_a_stem_matches_another_it_starts_with_from_four_letters(): void
    {
        $this->assertSame(2, LinkCandidates::match('seed', 'seed'));
        $this->assertSame(1, LinkCandidates::match('seed', 'seedhead'));
        $this->assertSame(1, LinkCandidates::match('seedhead', 'seed'));
        $this->assertSame(1, LinkCandidates::match('seed', 'seedh'), 'A row stemmed before the stemmer: its first five letters.');
        $this->assertSame(0, LinkCandidates::match('pot', 'potato'), 'Three letters is too short to match by its start.');
        $this->assertSame(0, LinkCandidates::match('seed', 'weed'));
    }

    public function test_the_same_stem_counts_double_one_starting_the_other_once_and_parts_weigh_three_two_one(): void
    {
        $draft = array_flip(['prune', 'appl', 'seed']);

        $this->assertSame([6, 0], LinkCandidates::score($this->row('a', 'Pruning', '/x'), $draft));
        $this->assertSame([4, 0], LinkCandidates::score($this->row('b', 'Other', '/pruning'), $draft));
        $this->assertSame([4, 0], LinkCandidates::score($this->row('c', 'Other', '/x', 'Pruning an apple'), $draft));
        $this->assertSame([3, 0], LinkCandidates::score($this->row('d', 'Seedheads', '/x'), $draft), '"seed" starts "seedhead": once.');
        $this->assertSame([0, 0], LinkCandidates::score($this->row('e', 'Roses', '/x'), $draft));
        $this->assertSame([0, 1], LinkCandidates::score($this->row('f', 'Contact', '/x', key: true), $draft), 'A key page breaks ties.');
        $this->assertSame([0, -1], LinkCandidates::score($this->row('g', 'Shrubs', '/x', kind: RowKind::Term), $draft), 'A listing comes after a page.');
    }

    public function test_seedheads_are_a_candidate_for_a_draft_about_seed(): void
    {
        $rows = [
            $this->row('roses', 'Choosing roses', '/journal/roses', group: 'journal', updated: '2026-10-01'),
            $this->row('seedheads', 'Why we leave the seedheads standing', '/journal/why-we-leave-the-seedheads-standing', 'Teasel, sedum and grasses look good in frost and feed the birds.', group: 'journal'),
        ];

        $this->assertSame(['seedheads', 'roses'], $this->ids(LinkCandidates::rank($rows, self::MEADOWS, 'journal', 'default', locale: 'en')));
    }

    public function test_old_rows_stemmed_by_five_letters_still_match(): void
    {
        $old = IndexRow::fromArray(['entry' => ['group' => 'journal', 'id' => 'old', 'site' => 'default'], 'scope' => 'link', 'title' => 'Why we leave the seedheads standing', 'url' => '/journal/seedheads', 'link' => 'entry::old', 'stems' => ['title' => ['leave', 'seedh', 'stand'], 'slug' => ['seedh'], 'summary' => []]]);
        $other = $this->row('other', 'Choosing roses', '/journal/roses', group: 'journal', updated: '2026-10-01');

        $this->assertNotNull($old);
        $this->assertSame(['old', 'other'], $this->ids(LinkCandidates::rank([$other, $old], self::MEADOWS, 'journal', 'default', locale: 'en')));

        $pruning = IndexRow::fromArray(['entry' => ['group' => 'journal', 'id' => 'pruning', 'site' => 'default'], 'scope' => 'link', 'title' => 'Pruning', 'url' => '/journal/p', 'link' => 'entry::pruning', 'stems' => ['title' => ['pruni'], 'slug' => [], 'summary' => []]]);
        $this->assertNotNull($pruning);
        $this->assertSame([6, 0], LinkCandidates::score($pruning, array_flip(LinkCandidates::draftStems(self::DRAFT, 'en', 1))), 'Matched against the draft\'s words cut to five letters, as before.');
        $this->assertSame(['pruning', 'other'], $this->ids(LinkCandidates::rank([$other, $pruning], self::DRAFT, 'journal', 'default', locale: 'en')));
    }

    public function test_key_pages_are_always_offered_whatever_their_words(): void
    {
        $rows = [
            $this->row('contact', 'Contact', '/contact', 'The kettle is on most weekdays.', key: true),
            $this->row('about', 'About', '/about', 'Who we are.', key: true),
            $this->row('tools', 'Garden tools', '/tools', kind: RowKind::Category, key: true),
        ];

        // Twelve pages about pruning in two groups would fill the list; the key pages stay on it.
        foreach (range(1, 12) as $i) {
            $rows[] = $this->row("p{$i}", "Pruning apple trees {$i}", "/p{$i}", group: $i % 2 ? 'journal' : 'guides');
        }

        $ids = $this->ids(LinkCandidates::rank($rows, self::DRAFT, 'journal', 'default', limit: 6, locale: 'en'));

        $this->assertCount(6, $ids);
        $this->assertContains('contact', $ids);
        $this->assertContains('about', $ids);
        $this->assertNotContains('tools', $ids, 'A listing isn\'t held a place: it competes like any other.');
        $this->assertSame(['about', 'contact'], array_slice($ids, -2), 'Below the pages that share words with the draft.');
    }

    public function test_key_pages_beyond_the_reserve_compete_like_any_page(): void
    {
        $rows = array_map(fn (int $i) => $this->row("k{$i}", "Key page {$i}", "/k{$i}", group: "g{$i}", key: true), range(1, 12));

        $this->assertCount(12, LinkCandidates::rank($rows, self::DRAFT, 'journal', 'default', locale: 'en'), 'Eight are held a place; the rest fill the list.');
    }

    public function test_the_list_is_filled_best_first_up_to_the_limit(): void
    {
        $rows = [];

        foreach (range(1, 10) as $g) {
            foreach (range(1, 4) as $i) {
                $rows[] = $this->row("g{$g}-{$i}", "Unrelated {$g} {$i}", "/g{$g}/{$i}", group: "group{$g}", updated: "2026-0{$i}-0{$g}");
            }
        }

        $rows[] = $this->row('best', 'Pruning apple trees in winter', '/best', group: 'group1');
        $ranked = LinkCandidates::rank($rows, self::DRAFT, 'journal', 'default', locale: 'en');

        $this->assertCount(LinkCandidates::LIMIT, $ranked, 'Filled, though only one page shares words with the draft.');
        $this->assertSame('best', $ranked[0]->entry?->id);
        $this->assertSame('g9-4', $ranked[1]->entry?->id, 'Then the newest.');
    }

    public function test_related_pages_rank_higher(): void
    {
        $rows = [
            $this->row('page', 'Meadows are not low-maintenance', '/journal/meadows', terms: ['tags::meadows'], links: ['entry::seeds-guide']),
            $this->row('plain', 'Opening hours', '/plain', group: 'other', updated: '2026-10-01'),
            $this->row('tagged', 'Another post', '/tagged', group: 'other', terms: ['tags::meadows', 'tags::seeds']),
            $this->row('tag', 'Meadows', '/tags/meadows', group: 'tags', kind: RowKind::Term, terms: ['tags::meadows']),
            $this->row('linker', 'A post', '/linker', group: 'other', links: ['statamic://entry::page']),
            $this->row('alike', 'A post', '/alike', group: 'other', links: ['entry::seeds-guide']),
            $this->row('sibling', 'A post', '/sibling', group: 'journal'),
        ];
        $text = "Something else entirely\n\nNothing in common with any of these.";
        $ranked = $this->ids(LinkCandidates::rank($rows, $text, 'journal', 'default', new EntryRef('pages', 'page', 'default'), locale: 'en'));

        // A shared term or a link here (+4), the term itself (+4, a listing: after), links alike (+2), the same group (+2), then the rest.
        $this->assertSame(['linker', 'tagged', 'tag', 'alike', 'sibling', 'plain'], $ranked);
        $this->assertSame([4, 0], LinkCandidates::score($rows[2], [], '', ['terms' => ['tags::meadows' => true]]));
        $this->assertSame([4, 0], LinkCandidates::score($rows[4], [], '', ['here' => ['entry::page' => true]]));
        $this->assertSame([2, 0], LinkCandidates::score($rows[5], [], '', ['linked' => ['entry::seeds-guide' => true]]));
        $this->assertSame([2, 0], LinkCandidates::score($rows[6], [], 'journal'));
    }

    public function test_a_page_linking_where_the_draft_links_ranks_higher(): void
    {
        $rows = [
            $this->row('plain', 'A post', '/plain', updated: '2026-10-01'),
            $this->row('alike', 'A post', '/alike', links: ['/contact']),
        ];

        $this->assertSame(['alike', 'plain'], $this->ids(LinkCandidates::rank($rows, "A draft\n\nWords.", 'journal', 'default', linked: ['https://example.test/contact'], locale: 'en')));
    }

    public function test_at_most_five_from_a_group_two_listings_and_the_limit(): void
    {
        $rows = [];

        foreach (range(1, 7) as $i) {
            $rows[] = $this->row("j{$i}", 'Pruning apple trees', "/j{$i}", group: 'journal');
            $rows[] = $this->row("t{$i}", 'Pruning apple trees', "/t{$i}", group: 'tags', kind: RowKind::Term);
        }

        $rows[] = $this->row('s1', 'Pruning', '/s1', group: 'services');
        $ranked = LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default', locale: 'en');
        $groups = array_count_values(array_map(fn (DigestEntry $e) => $e->entry->group, $ranked));

        $this->assertSame(['journal' => 5, 'tags' => 2, 'services' => 1], $groups);
        $this->assertCount(3, LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default', limit: 3, locale: 'en'));
    }

    public function test_full_and_link_rows_rank_alike(): void
    {
        $full = $this->row('full', 'Pruning apple trees', '/a', scope: IndexScope::Full, updated: '2026-01-01');
        $link = $this->row('link', 'Pruning apple trees', '/b', updated: '2026-02-01');

        $this->assertSame(['link', 'full'], $this->ids(LinkCandidates::rank([$full, $link], self::DRAFT, 'pages', 'default', locale: 'en')));
    }

    public function test_other_sites_the_page_itself_linked_pages_and_excluded_rows_are_skipped(): void
    {
        $rows = [
            $this->row('mine', 'Pruning apple trees', '/mine'),
            $this->row('unrelated', 'Opening hours', '/opening-hours'),
            $this->row('theirs', 'Pruning apple trees', '/theirs', site: 'cy'),
            $this->row('self', 'Pruning apple trees', '/self'),
            $this->row('linked', 'Pruning apple trees', '/linked'),
            $this->row('craft', 'Pruning apple trees', '/craft', link: '{entry:12@1:url}'),
            $this->row('noindex', 'Pruning apple trees', '/noindex', noindex: true),
            $this->row('cart', 'Pruning apple trees', '/shop/cart'),
            $this->row('home', 'Pruning apple trees', '/', key: true),
            $this->row('later', 'Pruning apple trees', '/later', liveFrom: '2099-01-01'),
        ];
        $ranked = LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default', new EntryRef('pages', 'self', 'default'), linked: ['statamic://entry::linked', '{entry:12@1:url||https://example.test/craft}'], locale: 'en');

        $this->assertSame(['mine', 'unrelated'], $this->ids($ranked), 'The list is filled, but never with these.');
    }

    public function test_link_keys_read_every_form_a_link_is_stored_in(): void
    {
        $this->assertSame('entry::abc', LinkCandidates::linkKey('statamic://entry::abc'));
        $this->assertSame('entry::abc', LinkCandidates::linkKey('entry::abc'));
        $this->assertSame('entry:12', LinkCandidates::linkKey('{entry:12@1:url||https://x.test/a}'));
        $this->assertSame('category:5', LinkCandidates::linkKey('{category:5:url}'));
        $this->assertSame('path:/contact', LinkCandidates::linkKey('https://x.test/contact/'));
        $this->assertSame('path:/contact', LinkCandidates::linkKey('/Contact'));
        $this->assertNull(LinkCandidates::linkKey('#gw-link:contact'));
        $this->assertNull(LinkCandidates::linkKey('mailto:a@b.test'));
    }

    public function test_stem_index_keys_find_every_row_a_draft_could_match(): void
    {
        $row = $this->row('seedheads', 'Why we leave the seedheads standing', '/journal/seedheads');

        $this->assertSame(['leav', 'seed', 'stan'], LinkCandidates::indexKeys($row));

        $keys = LinkCandidates::lookupKeys(self::MEADOWS, 'en');
        $this->assertContains('seed', $keys, 'Finds "seedhead" by its first four letters.');
        $this->assertContains('meado', $keys, 'And an older index, keyed by five letters.');
        $this->assertContains('meadow', $keys, 'Or by whole stems.');
    }

    public function test_a_draft_with_no_words_finds_nothing(): void
    {
        $this->assertSame([], LinkCandidates::rank([$this->row('a', 'Pruning', '/a')], '', 'pages', 'default'));
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $links
     */
    private function row(string $id, string $title, string $url, string $summary = '', string $group = 'pages', string $site = 'default', bool $key = false, RowKind $kind = RowKind::Entry, IndexScope $scope = IndexScope::Link, string $updated = '', mixed $link = null, bool $noindex = false, ?string $liveFrom = null, array $terms = [], array $links = []): IndexRow
    {
        return IndexRow::make(new EntryRef($group, $id, $site), $scope, $title, $url, $summary, ucfirst($group), $kind, $liveFrom, noindex: $noindex, key: $key, link: $link ?? 'entry::'.$id, updated: $updated, locale: 'en', terms: $terms, links: $links);
    }

    /**
     * @param  list<DigestEntry>  $entries
     * @return list<string>
     */
    private function ids(array $entries): array
    {
        return array_map(fn (DigestEntry $entry) => (string) $entry->entry?->id, $entries);
    }
}
