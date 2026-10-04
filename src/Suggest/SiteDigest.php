<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;

/**
 * The site's other entries the review call is shown, numbered e1, e2…:
 * the link candidates of the findings first, then the entries nearest the
 * page (EntryIndex::nearest()), at most LIMIT, each a title, an address
 * and a short summary (about 1,500 tokens). The model may point a link
 * only at an entry listed here, by its number; core turns the number back
 * into what a link stores.
 */
final class SiteDigest
{
    public const LIMIT = 30;

    /** @var array<string, DigestEntry> By id: "e1"… */
    private readonly array $entries;

    /**
     * @param  array<int, DigestEntry>  $entries
     */
    public function __construct(array $entries = [])
    {
        $byId = [];

        foreach (array_values(array_slice($entries, 0, self::LIMIT)) as $i => $entry) {
            $byId['e'.($i + 1)] = $entry;
        }

        $this->entries = $byId;
    }

    /**
     * @param  array<int, Finding>  $findings
     */
    public static function build(CheckContext $context, array $findings, int $limit = self::LIMIT): self
    {
        $entries = [];
        $seen = [];
        $add = function (DigestEntry $entry) use (&$entries, &$seen, $context): void {
            $key = $entry->entry?->key() ?? (is_scalar($entry->link) ? (string) $entry->link : '').'|'.$entry->url.'|'.$entry->title;

            if (! isset($seen[$key]) && ($entry->entry === null || $context->entry === null || ! $entry->entry->is($context->entry))) {
                $seen[$key] = true;
                $entries[] = $entry;
            }
        };

        foreach ($findings as $finding) {
            foreach (is_array($finding->meta['candidates'] ?? null) ? $finding->meta['candidates'] : [] as $candidate) {
                if (is_array($candidate) && is_string($candidate['title'] ?? null)) {
                    $add(new DigestEntry(null, $candidate['title'], is_string($candidate['url'] ?? null) ? $candidate['url'] : null, '', $candidate['value'] ?? null));
                }
            }
        }

        if ($context->index !== null && $context->entry !== null) {
            $text = $context->gaps->entry->title()."\n".implode("\n", array_map(fn (CheckText $text) => $text->plain, $context->texts()));

            foreach ($context->index->nearest($context->entry, mb_substr($text, 0, 4000), $limit) as $entry) {
                $add(new DigestEntry($entry->entry, $entry->title, $entry->url, mb_substr($entry->summary, 0, DigestEntry::SUMMARY), $entry->link, $entry->type));
            }
        }

        return new self(array_slice($entries, 0, $limit));
    }

    public function get(string $id): ?DigestEntry
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * @return array<string, DigestEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** The id of the entry a link stores, if it's listed. */
    public function idOf(mixed $link): ?string
    {
        foreach ($this->entries as $id => $entry) {
            if ($entry->link !== null && $entry->link === $link) {
                return $id;
            }
        }

        return null;
    }

    /** The `<site>` block's lines. */
    public function render(): string
    {
        $lines = [];

        foreach ($this->entries as $id => $entry) {
            $summary = trim((string) preg_replace('/\s+/u', ' ', $entry->summary));
            $lines[] = $id.' "'.str_replace('"', "'", $entry->title).'"'.($entry->type !== '' ? ' ('.$entry->type.')' : '').($entry->url !== null ? ' '.$entry->url : '').($summary !== '' ? ': '.$summary : '');
        }

        return implode("\n", $lines);
    }

    /** Every word the digest shows, for SourceCheck: a name or figure from a cited entry is sourced. */
    public function text(string $id): string
    {
        $entry = $this->get($id);

        return $entry === null ? '' : $entry->title."\n".$entry->summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(fn (DigestEntry $entry, string $id) => ['id' => $id] + $entry->toArray(), $this->entries, array_keys($this->entries)));
    }

    /** Whether some text names an entry by its title, for "a link the text mentions". */
    public function mentions(string $id, string $text): bool
    {
        $entry = $this->get($id);

        return $entry !== null && str_contains(' '.implode(' ', NormalisedText::words($text)).' ', ' '.implode(' ', NormalisedText::words($entry->title)).' ');
    }
}
