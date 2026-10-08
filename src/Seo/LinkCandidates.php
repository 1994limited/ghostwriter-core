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
 * (Suggest\LinkIndex::related()), so they rank alike. The list is a
 * shortlist for the model, which decides what's relevant (and the
 * verifier drops weak links), so it's filled rather than cut at a floor:
 *
 * - words: the draft's title, headings and first 200 words, without stop
 *   words, are stemmed (Seo\Stemmer: English, German, French, Dutch,
 *   Spanish) and matched against each row's title (3), slug (2) and
 *   summary (1): a stem the same counts double, one starting the other
 *   ("seed" and "seedhead") once;
 * - related pages: a term or category the page is filed under, or one
 *   it shares with the page (+4), a page linking to it (+4), a page
 *   linking where it links (+2, twice at most), the draft's own group (+2);
 * - key pages (navigation, level 1 of a structure) are always offered,
 *   up to KEY_PAGES, outside their group's cap, as the page a reader may
 *   want next ("tell us about your garden");
 * - then the rest fill the list up to LIMIT (25), at most PER_GROUP (5)
 *   from one group and LISTINGS (2) terms or categories, so a big
 *   Journal doesn't crowd out Services;
 * - ties: a key page, then not a listing, then the newer page.
 *
 * Full rows and link rows score alike; rows stemmed before Seo\Stemmer
 * (the first five letters) still match, by their start.
 */
final class LinkCandidates
{
    /** Candidates sent to the model, at most. */
    public const LIMIT = 25;

    /** From any one group, at most (key pages aside). */
    public const PER_GROUP = 5;

    /** Terms and categories, at most. */
    public const LISTINGS = 2;

    /** Key pages always offered, at most (and never more than a third of the list). */
    public const KEY_PAGES = 8;

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

    /** Letters a stem needs to match another by its start ("seed", "seedhead"); also the stem index's key length. */
    public const PREFIX = 4;

    /** Words of the draft's body read, after its title and headings. */
    public const DRAFT_WORDS = 200;

    public const TITLE = 3;

    public const SLUG = 2;

    public const SUMMARY = 1;

    /** A term or category the page is filed under, or shares with it. */
    public const SHARED_TERM = 4;

    /** A page that links to the page. */
    public const LINKS_HERE = 4;

    /** A page that links where the draft links, each (twice at most). */
    public const LINKS_ALIKE = 2;

    /** The draft's own group. */
    public const SAME_GROUP = 2;

    /**
     * The stems of a text: its words in lower case without the language's
     * stop words, words under three letters and bare numbers, stemmed in
     * the language (Seo\Stemmer; a language without a stemmer: the first
     * five letters), once each, in order.
     *
     * @param  string|null  $locale  "en_GB", "de"…; null or a language without phrase lists: no stop words.
     * @param  int  $version  IndexRow::STEMS; 1 for the first five letters, as rows stemmed before Seo\Stemmer have them.
     * @return list<string>
     */
    public static function stems(string $text, ?string $locale = null, int $version = IndexRow::STEMS): array
    {
        $stop = array_flip(self::stopWords($locale));
        $stems = [];

        foreach (NormalisedText::words($text) as $word) {
            if (mb_strlen($word) < 3 || isset($stop[$word]) || preg_match('/^\p{N}+$/u', $word)) {
                continue;
            }

            $stems[$version > 1 ? Stemmer::stem($word, $locale) : mb_substr($word, 0, Stemmer::PREFIX)] = true;
        }

        return array_map('strval', array_keys($stems));
    }

    /**
     * The stems of a draft that are matched: its title (the first line),
     * its Markdown headings and its first DRAFT_WORDS words.
     *
     * @return list<string>
     */
    public static function draftStems(string $text, ?string $locale = null, int $version = IndexRow::STEMS): array
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
            self::stems($title, $locale, $version),
            self::stems(implode("\n", $headings), $locale, $version),
            self::stems(implode(' ', array_slice($words, 0, self::DRAFT_WORDS)), $locale, $version),
        )));
    }

    /**
     * What a row is filed under in a stem index (a big site's, written by
     * the addon): each stem's first PREFIX letters, so a lookup by
     * lookupKeys() finds every row a draft's stems could match.
     *
     * @return list<string>
     */
    public static function indexKeys(IndexRow $row): array
    {
        $keys = [];

        foreach ($row->allStems() as $stem) {
            $keys[mb_substr($stem, 0, self::PREFIX)] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * The stem index keys to look a draft up by: each stem's first PREFIX
     * letters (indexKeys()), and, until the index is written again, the
     * whole stems and the words' first five letters an older index was
     * keyed by.
     *
     * @return list<string>
     */
    public static function lookupKeys(string $text, ?string $locale = null): array
    {
        $keys = [];

        foreach (self::draftStems($text, $locale) as $stem) {
            $keys[mb_substr($stem, 0, self::PREFIX)] = true;
            $keys[$stem] = true;
        }

        foreach (self::draftStems($text, $locale, 1) as $stem) {
            $keys[$stem] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * Whether two stems match: 2 the same, 1 when one starts the other
     * and the shorter has PREFIX letters or more ("seed" and "seedhead";
     * "prune" and an older row's "pruni"), 0 not.
     */
    public static function match(string $a, string $b): int
    {
        if ($a === $b) {
            return 2;
        }

        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;

        return mb_strlen($short) >= self::PREFIX && str_starts_with($long, $short) ? 1 : 0;
    }

    /**
     * How well a row matches a draft: [relevance, tie-break]. Relevance is
     * its words (title 3, slug 2, summary 1 a stem; doubled for the same
     * stem, single for one starting the other) and how it's related to
     * the page ($related: SHARED_TERM, LINKS_HERE, LINKS_ALIKE, SAME_GROUP);
     * the tie-break is +1 for a key page and −1 for a term or category.
     *
     * @param  array<string, mixed>  $draft  The draft's stems, as keys.
     * @param  array{terms?: array<string, mixed>, here?: array<string, mixed>, linked?: array<string, mixed>}  $related  The page's terms, the keys a link to it has, and the keys of the pages it links to, as keys.
     * @return array{0: int, 1: int}
     */
    public static function score(IndexRow $row, array $draft, string $group = '', array $related = []): array
    {
        $lookup = self::lookup($draft);

        return [self::words($row, $lookup) + self::related($row, $group, $related), ($row->key ? 1 : 0) - ($row->kind->isListing() ? 1 : 0)];
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
        $draft = self::draftStems($text, $locale);

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

        $own = null;
        $eligible = [];

        foreach ($rows as $row) {
            if ((string) ($row->entry->site ?? '') !== (string) ($site ?? '')) {
                continue;
            }

            if ($except !== null && $row->entry->is($except)) {
                $own = $row;

                continue;
            }

            if (Linkable::excluded($row, $now) === null && ! self::isLinked($row, $linkedKeys)) {
                $eligible[] = $row;
            }
        }

        // What the page is filed under, and how a link to it is written, from its own row.
        $here = [];

        foreach ($own === null ? [] : [self::linkKey($own->link), $own->path() === null ? null : 'path:'.rtrim(strtolower($own->path()), '/')] as $key) {
            if ($key !== null) {
                $here[$key] = true;
            }
        }

        $related = [
            'terms' => array_flip($own === null ? [] : $own->terms),
            'here' => $here,
            'linked' => $linkedKeys + array_flip($own === null ? [] : $own->links),
        ];
        // Rows stemmed before Seo\Stemmer are matched against the draft's words cut the same way.
        $lookups = [IndexRow::STEMS => self::lookup(array_flip($draft))];
        $scored = [];

        foreach ($eligible as $row) {
            $version = $row->stemsVersion() > 1 ? IndexRow::STEMS : 1;
            $lookups[$version] ??= self::lookup(array_flip(self::draftStems($text, $locale, $version)));
            $tie = ($row->key ? 1 : 0) - ($row->kind->isListing() ? 1 : 0);
            $scored[] = [self::words($row, $lookups[$version]) + self::related($row, $group, $related), $tie, $row->updated, $row];
        }

        usort($scored, fn (array $a, array $b) => [$b[0], $b[1], $b[2], $a[3]->title] <=> [$a[0], $a[1], $a[2], $b[3]->title]);

        // Key pages first, whatever their words, then the rest by score within the caps.
        $keys = min(self::KEY_PAGES, intdiv($limit, 3));
        $picked = [];

        foreach ($scored as $i => [, , , $row]) {
            if (count($picked) >= $keys) {
                break;
            }

            if ($row->key && ! $row->kind->isListing()) {
                $picked[$i] = true;
            }
        }

        $perGroup = [];
        $listings = 0;

        foreach ($scored as $i => [, , , $row]) {
            if (count($picked) >= $limit) {
                break;
            }

            if (isset($picked[$i]) || ($perGroup[$row->entry->group] ?? 0) >= self::PER_GROUP || ($row->kind->isListing() && $listings >= self::LISTINGS)) {
                continue;
            }

            $perGroup[$row->entry->group] = ($perGroup[$row->entry->group] ?? 0) + 1;
            $listings += $row->kind->isListing() ? 1 : 0;
            $picked[$i] = true;
        }

        ksort($picked);

        return array_values(array_map(fn (int $i) => $scored[$i][3]->digest(), array_keys($picked)));
    }

    /**
     * One form for the ways a link to a page can be written, so a page the
     * draft already links to is known: `entry::abc` for Statamic's
     * `statamic://entry::abc`, `entry:12` for Craft's `{entry:12@1:url||…}`
     * (or CKEditor's `https://…/x#entry:12@1:url`),
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

        // CKEditor's form of a reference, as the editor shows it: the address with `#entry:12@1:url`.
        if (preg_match('/#(entry|category|asset):(\d+)(?:@\d+)?(?::[\w.]*)?$/', $href, $m)) {
            return $m[1].':'.$m[2];
        }

        if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $href)) {
            return null;
        }

        $path = IndexRow::pathOf($href);

        return $path === null ? null : 'path:'.rtrim(strtolower($path), '/');
    }

    /**
     * The row of a site a link points at (Suggest\LinkLookup::linkRow()),
     * or null: a reference matches the row's link (`statamic://entry::abc`
     * and `entry::abc` alike; `{entry:12@1:url||…}` and `{entry:12}`
     * alike); an address matches the row's address by its path, and an
     * absolute one only a row whose address is on the same host, so a link
     * to another site's `/contact` never matches this site's Contact page.
     *
     * @param  iterable<IndexRow>  $rows
     */
    public static function rowFor(iterable $rows, string $href, int|string|null $site = null): ?IndexRow
    {
        $key = self::linkKey($href);

        if ($key === null) {
            return null;
        }

        $address = str_starts_with($key, 'path:');
        $host = self::host($href);

        foreach ($rows as $row) {
            if ((string) ($row->entry->site ?? '') !== (string) ($site ?? '')) {
                continue;
            }

            if (! $address) {
                if (self::linkKey($row->link) === $key) {
                    return $row;
                }

                continue;
            }

            $path = $row->path();

            if ($path !== null && 'path:'.rtrim(strtolower($path), '/') === $key && ($host === null || $host === self::host((string) $row->url))) {
                return $row;
            }
        }

        return null;
    }

    /** The host of an absolute address, without `www.`; null for anything else. */
    private static function host(string $href): ?string
    {
        if (preg_match('#^https?://#i', trim($href)) !== 1) {
            return null;
        }

        $host = parse_url(trim($href), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? (string) preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /**
     * A draft's stems for matching: the stems, as keys, and those of
     * PREFIX letters or more by their first PREFIX letters.
     *
     * @param  array<string, mixed>  $draft
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private static function lookup(array $draft): array
    {
        $buckets = [];

        foreach (array_keys($draft) as $stem) {
            $stem = (string) $stem;

            if (mb_strlen($stem) >= self::PREFIX) {
                $buckets[mb_substr($stem, 0, self::PREFIX)][] = $stem;
            }
        }

        return [$draft, $buckets];
    }

    /**
     * A row's words against the draft's: 2 a stem the same, 1 one starting
     * the other, times the part's weight.
     *
     * @param  array{0: array<string, mixed>, 1: array<string, list<string>>}  $lookup
     */
    private static function words(IndexRow $row, array $lookup): int
    {
        [$exact, $buckets] = $lookup;
        $score = 0;

        foreach (['title' => self::TITLE, 'slug' => self::SLUG, 'summary' => self::SUMMARY] as $part => $weight) {
            foreach ($row->stemsOf($part) as $stem) {
                if (isset($exact[$stem])) {
                    $score += 2 * $weight;

                    continue;
                }

                foreach (mb_strlen($stem) >= self::PREFIX ? ($buckets[mb_substr($stem, 0, self::PREFIX)] ?? []) : [] as $other) {
                    if (self::match($stem, $other) > 0) {
                        $score += $weight;

                        break;
                    }
                }
            }
        }

        return $score;
    }

    /**
     * How a row is related to the page, beyond its words.
     *
     * @param  array{terms?: array<string, mixed>, here?: array<string, mixed>, linked?: array<string, mixed>}  $related
     */
    private static function related(IndexRow $row, string $group, array $related): int
    {
        $score = $group !== '' && $row->entry->group === $group ? self::SAME_GROUP : 0;
        $terms = $related['terms'] ?? [];

        if ($terms !== [] && $row->terms !== []) {
            $score += self::SHARED_TERM * min(2, count(array_intersect_key(array_flip($row->terms), $terms)));
        }

        if ($row->links !== []) {
            $links = array_flip($row->links);

            if (array_intersect_key($links, $related['here'] ?? []) !== []) {
                $score += self::LINKS_HERE;
            }

            $score += self::LINKS_ALIKE * min(2, count(array_intersect_key($links, $related['linked'] ?? [])));
        }

        return $score;
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
