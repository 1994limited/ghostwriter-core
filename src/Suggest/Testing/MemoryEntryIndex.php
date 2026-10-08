<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Linkable;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkLookup;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;

/**
 * An EntryIndex and LinkIndex over rows held in memory, for tests and the
 * demo, and the reference for what the ports must do (EntryIndexContract,
 * LinkIndexContract): full rows with their paragraphs' shingles, link rows
 * without, sharing() and nearest() over full rows only, related() and
 * linkRow() over both.
 */
final class MemoryEntryIndex implements EntryIndex, LinkIndex, LinkLookup
{
    /** @var array<string, array{row: IndexRow, paragraphs: list<list<int>>}> */
    private array $entries = [];

    /**
     * @param  string|null  $locale  The site's language, as the addons' indexes read it: stop words and stems for related().
     */
    public function __construct(private readonly ?string $locale = null) {}

    /**
     * A full row: a page of one of Ghostwriter's own groups.
     *
     * @param  array<int, string>  $paragraphs
     */
    public function add(EntryRef $ref, string $title, array $paragraphs = [], ?string $url = null, string $summary = '', mixed $link = null, string $type = '', ?string $locale = null): self
    {
        $this->entries[$ref->key()] = [
            'row' => IndexRow::make($ref, IndexScope::Full, $title, $url, $summary, $type, link: $link, locale: $locale),
            'paragraphs' => array_values(array_map(fn (string $paragraph) => Shingles::of($paragraph), array_filter($paragraphs, fn (string $p) => count(NormalisedText::words($p)) >= Shingles::MIN_WORDS))),
        ];

        return $this;
    }

    /**
     * Any row, as an addon's link source writes it; one Linkable::keep()
     * refuses (a draft, no address) is forgotten instead, as the addons do.
     * A link row has no paragraphs.
     */
    public function put(IndexRow $row): self
    {
        if (! Linkable::keep($row)) {
            $this->forget($row->entry);

            return $this;
        }

        $this->entries[$row->entry->key()] = [
            'row' => $row,
            'paragraphs' => $row->scope === IndexScope::Full ? ($this->entries[$row->entry->key()]['paragraphs'] ?? []) : [],
        ];

        return $this;
    }

    public function forget(EntryRef $ref): void
    {
        unset($this->entries[$ref->key()]);
    }

    public function row(EntryRef $ref): ?IndexRow
    {
        return $this->entries[$ref->key()]['row'] ?? null;
    }

    public function sharing(array $shingles, EntryRef $except, int $limit = 5): array
    {
        $found = [];

        foreach ($this->entries as $key => $entry) {
            $row = $entry['row'];

            if ($key === $except->key() || $row->entry->site !== $except->site || $row->scope !== IndexScope::Full) {
                continue;
            }

            foreach ($entry['paragraphs'] as $paragraph) {
                $shared = count(array_intersect($shingles, $paragraph));

                if ($shared > 0) {
                    $found[] = [$shared, new IndexedParagraph($row->entry, $row->title, $paragraph, $row->url)];
                }
            }
        }

        usort($found, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_slice(array_map(fn (array $item) => $item[1], $found), 0, $limit);
    }

    public function nearest(EntryRef $entry, string $text, int $limit = 20): array
    {
        $words = array_flip(NormalisedText::words($text));
        $scored = [];

        foreach ($this->entries as $key => $other) {
            $row = $other['row'];

            if ($key === $entry->key() || $row->entry->site !== $entry->site || $row->scope !== IndexScope::Full) {
                continue;
            }

            $shared = count(array_intersect_key(array_flip(NormalisedText::words($row->title.' '.$row->summary)), $words));
            $scored[] = [$row->entry->group === $entry->group ? 1 : 0, $shared, $row];
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_slice(array_map(fn (array $item) => new DigestEntry($item[2]->entry, $item[2]->title, $item[2]->url, mb_substr($item[2]->summary, 0, DigestEntry::SUMMARY), $item[2]->link, $item[2]->type), $scored), 0, $limit);
    }

    public function related(string $text, string $group, int|string|null $site = null, ?EntryRef $except = null, int $limit = LinkCandidates::LIMIT, array $linked = [], ?DateTimeImmutable $now = null): array
    {
        return LinkCandidates::rank(array_map(fn (array $entry) => $entry['row'], $this->entries), $text, $group, $site, $except, $limit, $linked, $now, $this->locale);
    }

    public function linkRow(string $href, int|string|null $site = null): ?IndexRow
    {
        return LinkCandidates::rowFor(array_map(fn (array $entry) => $entry['row'], $this->entries), $href, $site);
    }
}
