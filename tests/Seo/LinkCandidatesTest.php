<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use DateTimeImmutable;
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

    public function test_stems_drop_stop_words_short_words_and_numbers_and_cut_to_five_letters(): void
    {
        $this->assertSame(['pruni', 'fruit', 'trees', 'winte'], LinkCandidates::stems('Pruning the fruit trees in 2026 winter', 'en'));
        $this->assertSame(['garte', 'planu'], LinkCandidates::stems('Der Garten und die Planung', 'de'));
        $this->assertContains('the', LinkCandidates::stems('the garden'), 'No language: no stop words.');
    }

    public function test_draft_stems_read_the_title_headings_and_first_200_words(): void
    {
        $body = str_repeat('filler ', 200).'zebra';
        $stems = LinkCandidates::draftStems("Title words\n\n## A heading\n\n{$body}", 'en');

        $this->assertContains('title', $stems);
        $this->assertContains('headi', $stems);
        $this->assertContains('fille', $stems);
        $this->assertNotContains('zebra', $stems);
    }

    public function test_title_slug_and_summary_weigh_three_two_and_one(): void
    {
        $draft = array_flip(['pruni', 'apple']);

        $this->assertSame([3, 0], LinkCandidates::score($this->row('a', 'Pruning', '/x'), $draft));
        $this->assertSame([2, 0], LinkCandidates::score($this->row('b', 'Other', '/pruning'), $draft));
        $this->assertSame([2, 0], LinkCandidates::score($this->row('c', 'Other', '/x', 'Pruning an apple'), $draft));
        $this->assertNull(LinkCandidates::score($this->row('d', 'Other', '/x', 'Pruning roses'), $draft), 'One summary stem is below the floor.');
    }

    public function test_key_pages_own_group_and_listings_only_break_ties(): void
    {
        $rows = [
            $this->row('plain', 'Pruning apple trees', '/plain'),
            $this->row('key', 'Pruning guide', '/key', key: true),
            $this->row('term', 'Pruning apple and pear trees', '/term', kind: RowKind::Term),
            $this->row('same', 'Pruning', '/same', group: 'journal'),
        ];

        // term and plain share 3 and 2 title stems; key (a key page) and same (the draft's group) tie at one, then by title.
        $this->assertSame(['term', 'plain', 'same', 'key'], $this->ids(LinkCandidates::rank($rows, self::DRAFT, 'journal', 'default', now: new DateTimeImmutable)), 'More shared stems win; tie-breaks after.');

        $tied = [
            $this->row('plain', 'Pruning', '/a', updated: '2026-01-01'),
            $this->row('term', 'Pruning', '/b', kind: RowKind::Term, updated: '2026-09-01'),
            $this->row('key', 'Pruning', '/c', key: true),
            $this->row('newer', 'Pruning', '/d', updated: '2026-06-01'),
        ];

        $this->assertSame(['key', 'newer', 'plain', 'term'], $this->ids(LinkCandidates::rank($tied, self::DRAFT, 'journal', 'default')));
    }

    public function test_at_most_four_from_a_group_two_listings_and_the_limit(): void
    {
        $rows = [];

        foreach (range(1, 6) as $i) {
            $rows[] = $this->row("j{$i}", 'Pruning apple trees', "/j{$i}", group: 'journal');
            $rows[] = $this->row("t{$i}", 'Pruning apple trees', "/t{$i}", group: 'tags', kind: RowKind::Term);
        }

        $rows[] = $this->row('s1', 'Pruning', '/s1', group: 'services');
        $ranked = LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default');
        $groups = array_count_values(array_map(fn (DigestEntry $e) => $e->entry->group, $ranked));

        $this->assertSame(['journal' => 4, 'tags' => 2, 'services' => 1], $groups);
        $this->assertCount(3, LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default', limit: 3));
    }

    public function test_full_and_link_rows_rank_alike(): void
    {
        $full = $this->row('full', 'Pruning apple trees', '/a', scope: IndexScope::Full, updated: '2026-01-01');
        $link = $this->row('link', 'Pruning apple trees', '/b', updated: '2026-02-01');

        $this->assertSame(['link', 'full'], $this->ids(LinkCandidates::rank([$full, $link], self::DRAFT, 'pages', 'default')));
    }

    public function test_other_sites_the_page_itself_linked_pages_and_excluded_rows_are_skipped(): void
    {
        $rows = [
            $this->row('mine', 'Pruning apple trees', '/mine'),
            $this->row('theirs', 'Pruning apple trees', '/theirs', site: 'cy'),
            $this->row('self', 'Pruning apple trees', '/self'),
            $this->row('linked', 'Pruning apple trees', '/linked'),
            $this->row('craft', 'Pruning apple trees', '/craft', link: '{entry:12@1:url}'),
            $this->row('noindex', 'Pruning apple trees', '/noindex', noindex: true),
            $this->row('cart', 'Pruning apple trees', '/shop/cart'),
        ];
        $ranked = LinkCandidates::rank($rows, self::DRAFT, 'pages', 'default', new EntryRef('pages', 'self', 'default'), linked: ['statamic://entry::linked', '{entry:12@1:url||https://example.test/craft}']);

        $this->assertSame(['mine'], $this->ids($ranked));
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

    public function test_a_draft_with_no_words_finds_nothing(): void
    {
        $this->assertSame([], LinkCandidates::rank([$this->row('a', 'Pruning', '/a')], '', 'pages', 'default'));
    }

    private function row(string $id, string $title, string $url, string $summary = '', string $group = 'pages', string $site = 'default', bool $key = false, RowKind $kind = RowKind::Entry, IndexScope $scope = IndexScope::Link, string $updated = '', mixed $link = null, bool $noindex = false): IndexRow
    {
        return IndexRow::make(new EntryRef($group, $id, $site), $scope, $title, $url, $summary, ucfirst($group), $kind, noindex: $noindex, key: $key, link: $link ?? 'entry::'.$id, updated: $updated, locale: 'en');
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
