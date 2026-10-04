<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;

/**
 * One page of the site as the entry index keeps it, in either scope
 * (IndexScope): what related() scores and what a link to it stores. Each
 * addon keeps rows in its own shape (Statamic JSON beside the revisit
 * shards, Craft `ghostwriter_entry_index`, Filament
 * `ghostwriter_index_entries`) and reads them back through toArray() and
 * fromArray(). Full rows also keep their paragraphs' shingles, which aren't
 * part of this.
 *
 * Seo\Linkable says whether a row may be linked to, on a given day; rows of
 * unpublished pages aren't kept at all.
 */
final class IndexRow
{
    /**
     * @param  string|null  $url  The public address ('/garden-services/winter-care' or absolute); null: not linkable.
     * @param  string  $summary  At most DigestEntry::SUMMARY characters: the SEO description, else the first text.
     * @param  string  $type  The group's label as editors see it: "Pages", "Journal", "Plant categories".
     * @param  string|null  $liveFrom  ISO date a scheduled page goes live; null when it's live already.
     * @param  string|null  $liveUntil  ISO date it expires; null when it doesn't.
     * @param  bool  $noindex  An SEO addon or field asks search engines not to index it.
     * @param  bool  $key  A key page: in a navigation tree, or level 1 of a structure.
     * @param  mixed  $link  What a link to it stores: 'entry::abc', '{entry:12@1:url}', or the public URL.
     * @param  array{title?: list<string>, slug?: list<string>, summary?: list<string>}  $stems  LinkCandidates::stems() of each part.
     * @param  string  $updated  When the page was last changed (ISO), for "newer first" and staleness.
     * @param  bool  $published  Published (or scheduled) for the row's site; false rows are never stored.
     * @param  string|null  $indexed  When the row was written (ISO), for the daily pass.
     */
    public function __construct(
        public readonly EntryRef $entry,
        public readonly IndexScope $scope,
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $summary = '',
        public readonly string $type = '',
        public readonly RowKind $kind = RowKind::Entry,
        public readonly ?string $liveFrom = null,
        public readonly ?string $liveUntil = null,
        public readonly bool $noindex = false,
        public readonly bool $key = false,
        public readonly mixed $link = null,
        public readonly array $stems = [],
        public readonly string $updated = '',
        public readonly bool $published = true,
        public readonly ?string $indexed = null,
    ) {}

    /**
     * A row with its summary trimmed and its stems worked out from its
     * title, slug and summary, in the site's language.
     *
     * @param  string|null  $locale  The site's locale ("en_GB", "de"); null for language-neutral stems.
     */
    public static function make(
        EntryRef $entry,
        IndexScope $scope,
        string $title,
        ?string $url,
        string $summary = '',
        string $type = '',
        RowKind $kind = RowKind::Entry,
        ?string $liveFrom = null,
        ?string $liveUntil = null,
        bool $noindex = false,
        bool $key = false,
        mixed $link = null,
        string $updated = '',
        bool $published = true,
        ?string $indexed = null,
        ?string $locale = null,
    ): self {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $summary = rtrim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $summary)), 0, DigestEntry::SUMMARY));

        return new self(
            $entry, $scope, $title, $url, $summary, $type, $kind, $liveFrom, $liveUntil, $noindex, $key, $link,
            [
                'title' => LinkCandidates::stems($title, $locale),
                'slug' => LinkCandidates::stems(str_replace(['-', '_'], ' ', self::slugOf($url)), $locale),
                'summary' => LinkCandidates::stems($summary, $locale),
            ],
            $updated, $published, $indexed,
        );
    }

    /** The last segment of the row's address: "winter-care" for '/garden-services/winter-care'; '' for the home page. */
    public function slug(): string
    {
        return self::slugOf($this->url);
    }

    /** The address's path, '/' for the home page; null without an address. */
    public function path(): ?string
    {
        return self::pathOf($this->url);
    }

    /**
     * Every stem of the row, once, for a stem index.
     *
     * @return list<string>
     */
    public function allStems(): array
    {
        return array_values(array_unique(array_merge($this->stemsOf('title'), $this->stemsOf('slug'), $this->stemsOf('summary'))));
    }

    /**
     * @return list<string>
     */
    public function stemsOf(string $part): array
    {
        $stems = $this->stems[$part] ?? [];

        return is_array($stems) ? array_values(array_filter($stems, 'is_string')) : [];
    }

    /** The row as the model is shown it, with its type. */
    public function digest(): DigestEntry
    {
        return new DigestEntry($this->entry, $this->title, $this->url, mb_substr($this->summary, 0, DigestEntry::SUMMARY), $this->link, $this->type);
    }

    public function withScope(IndexScope $scope): self
    {
        return new self($this->entry, $scope, $this->title, $this->url, $this->summary, $this->type, $this->kind, $this->liveFrom, $this->liveUntil, $this->noindex, $this->key, $this->link, $this->stems, $this->updated, $this->published, $this->indexed);
    }

    public function withIndexed(?string $indexed): self
    {
        return new self($this->entry, $this->scope, $this->title, $this->url, $this->summary, $this->type, $this->kind, $this->liveFrom, $this->liveUntil, $this->noindex, $this->key, $this->link, $this->stems, $this->updated, $this->published, $indexed);
    }

    /**
     * @return array{entry: array{group: string, id: int|string, site: int|string|null}, scope: string, title: string, url: ?string, summary: string, type: string, kind: string, live_from: ?string, live_until: ?string, noindex: bool, key: bool, link: mixed, stems: array<string, list<string>>, updated: string, published: bool, indexed: ?string}
     */
    public function toArray(): array
    {
        return [
            'entry' => $this->entry->toArray(),
            'scope' => $this->scope->value,
            'title' => $this->title,
            'url' => $this->url,
            'summary' => $this->summary,
            'type' => $this->type,
            'kind' => $this->kind->value,
            'live_from' => $this->liveFrom,
            'live_until' => $this->liveUntil,
            'noindex' => $this->noindex,
            'key' => $this->key,
            'link' => $this->link,
            'stems' => ['title' => $this->stemsOf('title'), 'slug' => $this->stemsOf('slug'), 'summary' => $this->stemsOf('summary')],
            'updated' => $this->updated,
            'published' => $this->published,
            'indexed' => $this->indexed,
        ];
    }

    /**
     * A row from toArray(), or from a row written before link rows existed
     * (no scope: a full row; no stems: worked out again, language-neutral).
     *
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array, ?string $locale = null): ?self
    {
        if (! is_array($array['entry'] ?? null)) {
            return null;
        }

        try {
            $entry = EntryRef::fromArray($array['entry']);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $string = fn (string $key) => is_string($array[$key] ?? null) ? $array[$key] : null;
        $stems = is_array($array['stems'] ?? null) ? $array['stems'] : null;
        $row = new self(
            $entry,
            IndexScope::tryFrom((string) $string('scope')) ?? IndexScope::Full,
            (string) $string('title'),
            $string('url'),
            (string) $string('summary'),
            (string) $string('type'),
            RowKind::tryFrom((string) $string('kind')) ?? RowKind::Entry,
            $string('live_from'),
            $string('live_until'),
            (bool) ($array['noindex'] ?? false),
            (bool) ($array['key'] ?? false),
            $array['link'] ?? null,
            [],
            (string) $string('updated'),
            (bool) ($array['published'] ?? true),
            $string('indexed'),
        );

        if ($stems === null) {
            return self::make($row->entry, $row->scope, $row->title, $row->url, $row->summary, $row->type, $row->kind, $row->liveFrom, $row->liveUntil, $row->noindex, $row->key, $row->link, $row->updated, $row->published, $row->indexed, $locale);
        }

        $parts = [];

        foreach (['title', 'slug', 'summary'] as $part) {
            $parts[$part] = is_array($stems[$part] ?? null) ? array_values(array_filter($stems[$part], 'is_string')) : [];
        }

        return new self($row->entry, $row->scope, $row->title, $row->url, $row->summary, $row->type, $row->kind, $row->liveFrom, $row->liveUntil, $row->noindex, $row->key, $row->link, $parts, $row->updated, $row->published, $row->indexed);
    }

    /** The path of an address, relative or absolute: '/' for the home page; null without one. */
    public static function pathOf(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        return '/'.trim($path, '/');
    }

    private static function slugOf(?string $url): string
    {
        $path = self::pathOf($url);

        if ($path === null || $path === '/') {
            return '';
        }

        $segments = explode('/', trim($path, '/'));

        return rawurldecode((string) end($segments));
    }
}
