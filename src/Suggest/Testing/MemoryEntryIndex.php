<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexedParagraph;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;

/**
 * An EntryIndex over entries held in memory, for tests and the demo, and
 * the reference for what the port must do (EntryIndexContract).
 */
final class MemoryEntryIndex implements EntryIndex
{
    /** @var array<string, array{ref: EntryRef, title: string, url: ?string, summary: string, link: mixed, paragraphs: list<list<int>>}> */
    private array $entries = [];

    /**
     * @param  array<int, string>  $paragraphs
     */
    public function add(EntryRef $ref, string $title, array $paragraphs = [], ?string $url = null, string $summary = '', mixed $link = null): self
    {
        $this->entries[$ref->key()] = [
            'ref' => $ref,
            'title' => $title,
            'url' => $url,
            'summary' => $summary,
            'link' => $link,
            'paragraphs' => array_values(array_map(fn (string $paragraph) => Shingles::of($paragraph), array_filter($paragraphs, fn (string $p) => count(NormalisedText::words($p)) >= Shingles::MIN_WORDS))),
        ];

        return $this;
    }

    public function forget(EntryRef $ref): void
    {
        unset($this->entries[$ref->key()]);
    }

    public function sharing(array $shingles, EntryRef $except, int $limit = 5): array
    {
        $found = [];

        foreach ($this->entries as $key => $entry) {
            if ($key === $except->key() || $entry['ref']->site !== $except->site) {
                continue;
            }

            foreach ($entry['paragraphs'] as $paragraph) {
                $shared = count(array_intersect($shingles, $paragraph));

                if ($shared > 0) {
                    $found[] = [$shared, new IndexedParagraph($entry['ref'], $entry['title'], $paragraph, $entry['url'])];
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
            if ($key === $entry->key() || $other['ref']->site !== $entry->site) {
                continue;
            }

            $shared = count(array_intersect_key(array_flip(NormalisedText::words($other['title'].' '.$other['summary'])), $words));
            $scored[] = [$other['ref']->group === $entry->group ? 1 : 0, $shared, $other];
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_slice(array_map(fn (array $item) => new DigestEntry($item[2]['ref'], $item[2]['title'], $item[2]['url'], mb_substr($item[2]['summary'], 0, DigestEntry::SUMMARY), $item[2]['link']), $scored), 0, $limit);
    }
}
