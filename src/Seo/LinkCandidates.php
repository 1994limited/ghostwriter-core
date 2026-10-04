<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;

/**
 * Which of a site's pages a draft could link to, best first, with no
 * model (SEO layer §7.1, "Ranking"). Shared by the three addons' indexes
 * (Suggest\LinkIndex::related()), so they rank alike:
 *
 * - the draft's title, headings and first 200 words, without stop words,
 *   are matched by stem (the first five letters, so "pruning" meets
 *   "prune") against each row's title (3 a stem), slug (2) and summary (1);
 * - a floor: a row needs a shared stem in its title or slug, or two in its
 *   summary;
 * - ties are broken by a key page (+1), the draft's own group (+1) and a
 *   term or category (−1), then the newer page;
 * - at most LIMIT (25), at most PER_GROUP (4) from one group and LISTINGS
 *   (2) terms or categories, so a big Journal doesn't crowd out Services.
 *
 * Full rows and link rows score alike.
 */
final class LinkCandidates
{
    /** Candidates sent to the model, at most. */
    public const LIMIT = 25;

    /** From any one group, at most. */
    public const PER_GROUP = 4;

    /** Terms and categories, at most. */
    public const LISTINGS = 2;

    /** Link rows kept per group and site: key pages, then the most recently updated. */
    public const GROUP_ROWS = 5000;

    /** Link rows kept per site, at most. */
    public const SITE_ROWS = 20000;

    /** Above this many rows a site's rows are narrowed by its stem index before scoring. */
    public const STEM_INDEX_ABOVE = 5000;

    /** Rows written per chunk while the index is built. */
    public const CHUNK = 500;

    /** Rows written per daily run, at most. */
    public const PER_RUN = 5000;

    /** Letters of a word that make its stem. */
    public const STEM = 5;

    /** Words of the draft's body read, after its title and headings. */
    public const DRAFT_WORDS = 200;

    public const TITLE = 3;

    public const SLUG = 2;

    public const SUMMARY = 1;

    /**
     * The stems of a text: its words in lower case without the language's
     * stop words, words under three letters and bare numbers, cut to
     * STEM letters, once each, in order.
     *
     * @param  string|null  $locale  "en_GB", "de"…; null or a language without phrase lists: no stop words.
     * @return list<string>
     */
    public static function stems(string $text, ?string $locale = null): array
    {
        $stop = array_flip(self::stopWords($locale));
        $stems = [];

        foreach (NormalisedText::words($text) as $word) {
            if (mb_strlen($word) < 3 || isset($stop[$word]) || preg_match('/^\p{N}+$/u', $word)) {
                continue;
            }

            $stems[mb_substr($word, 0, self::STEM)] = true;
        }

        return array_map('strval', array_keys($stems));
    }

    /**
     * The stems of a draft that are matched: its title (the first line),
     * its Markdown headings and its first DRAFT_WORDS words.
     *
     * @return list<string>
     */
    public static function draftStems(string $text, ?string $locale = null): array
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $title = '';
        $headings = [];
        $body = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($title === '') {
                $title = (string) preg_replace('/^#+\s*/u', '', $line);

                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $m)) {
                $headings[] = $m[1];

                continue;
            }

            $body[] = $line;
        }

        $words = preg_split('/\s+/u', (string) preg_replace('/\]\([^)]*\)|<[^>]*>/u', ' ', implode(' ', $body)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_merge(
            self::stems($title, $locale),
            self::stems(implode("\n", $headings), $locale),
            self::stems(implode(' ', array_slice($words, 0, self::DRAFT_WORDS)), $locale),
        )));
    }

    /**
     * How well a row matches a draft's stems: [relevance, tie-break], or
     * null below the floor. Relevance is 3 a shared title stem, 2 a slug
     * stem and 1 a summary stem; the tie-break is +1 for a key page, +1
     * for the draft's own group and −1 for a term or category.
     *
     * @param  array<string, mixed>  $draft  The draft's stems, as keys.
     * @return array{0: int, 1: int}|null
     */
    public static function score(IndexRow $row, array $draft, string $group = ''): ?array
    {
        $title = count(array_intersect_key(array_flip($row->stemsOf('title')), $draft));
        $slug = count(array_intersect_key(array_flip($row->stemsOf('slug')), $draft));
        $summary = count(array_intersect_key(array_flip($row->stemsOf('summary')), $draft));

        if ($title === 0 && $slug === 0 && $summary < 2) {
            return null;
        }

        $tie = ($row->key ? 1 : 0) + ($group !== '' && $row->entry->group === $group ? 1 : 0) - ($row->kind->isListing() ? 1 : 0);

        return [self::TITLE * $title + self::SLUG * $slug + self::SUMMARY * $summary, $tie];
    }

    /**
     * The best candidates among a site's rows for a draft, as the model is
     * shown them. Rows of another site, the page itself, pages the draft
     * links to already and rows Linkable leaves out on $now are skipped.
     *
     * @param  iterable<IndexRow>  $rows
     * @param  list<string>  $linked  Hrefs already in the draft.
     * @return list<DigestEntry>
     */
    public static function rank(
        iterable $rows,
        string $text,
        string $group,
        int|string|null $site = null,
        ?EntryRef $except = null,
        int $limit = self::LIMIT,
        array $linked = [],
        ?DateTimeImmutable $now = null,
        ?string $locale = null,
    ): array {
        $draft = array_flip(self::draftStems($text, $locale));

        if ($draft === [] || $limit <= 0) {
            return [];
        }

        $now ??= new DateTimeImmutable;
        $linkedKeys = [];

        foreach ($linked as $href) {
            if (($key = self::linkKey($href)) !== null) {
                $linkedKeys[$key] = true;
            }
        }

        $scored = [];

        foreach ($rows as $row) {
            if ((string) ($row->entry->site ?? '') !== (string) ($site ?? '')
                || ($except !== null && $row->entry->is($except))
                || Linkable::excluded($row, $now) !== null
                || self::isLinked($row, $linkedKeys)) {
                continue;
            }

            $score = self::score($row, $draft, $group);

            if ($score !== null) {
                $scored[] = [$score[0], $score[1], $row->updated, $row];
            }
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1], $b[2], $a[3]->title] <=> [$a[0], $a[1], $a[2], $b[3]->title]);

        $picked = [];
        $perGroup = [];
        $listings = 0;

        foreach ($scored as [, , , $row]) {
            if (($perGroup[$row->entry->group] ?? 0) >= self::PER_GROUP || ($row->kind->isListing() && $listings >= self::LISTINGS)) {
                continue;
            }

            $perGroup[$row->entry->group] = ($perGroup[$row->entry->group] ?? 0) + 1;
            $listings += $row->kind->isListing() ? 1 : 0;
            $picked[] = $row->digest();

            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * One form for the ways a link to a page can be written, so a page the
     * draft already links to is known: `entry::abc` for Statamic's
     * `statamic://entry::abc`, `entry:12` for Craft's `{entry:12@1:url||…}`,
     * `path:/contact` for an address. Null for anything else (`#gw-link:`,
     * `mailto:`).
     */
    public static function linkKey(mixed $href): ?string
    {
        if (! is_string($href) || trim($href) === '') {
            return null;
        }

        $href = trim($href);

        if (str_starts_with(strtolower($href), 'statamic://')) {
            $href = substr($href, strlen('statamic://'));
        }

        if (preg_match('/^(\w+)::(.+)$/', $href, $m)) {
            return strtolower($m[1]).'::'.$m[2];
        }

        if (preg_match('/^\{(\w+):(\d+)(?:@\d+)?(?::[\w.]*)?(?:\|\|.*)?\}$/s', $href, $m)) {
            return strtolower($m[1]).':'.$m[2];
        }

        if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $href)) {
            return null;
        }

        $path = IndexRow::pathOf($href);

        return $path === null ? null : 'path:'.rtrim(strtolower($path), '/');
    }

    /**
     * @return list<string>
     */
    private static function stopWords(?string $locale): array
    {
        return $locale === null || $locale === '' ? [] : (Phrases::for($locale)->stopWords ?? []);
    }

    /**
     * @param  array<string, true>  $linked
     */
    private static function isLinked(IndexRow $row, array $linked): bool
    {
        if ($linked === []) {
            return false;
        }

        foreach ([self::linkKey($row->link), $row->path() === null ? null : 'path:'.rtrim(strtolower($row->path()), '/')] as $key) {
            if ($key !== null && isset($linked[$key])) {
                return true;
            }
        }

        return false;
    }
}
