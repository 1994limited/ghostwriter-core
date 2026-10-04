<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkLookup;

/**
 * The writer's "never use a made-up address" rule, in code (SEO layer
 * §5.2). After every writer turn, each link in the new draft must be one
 * of:
 *
 * - a link the previous draft had (the same href, or the same page);
 * - a link the SEO pass added;
 * - a `#gw-link:` marker (a link only the editor can choose);
 * - an outside address, `mailto:` or `tel:` that appears in the brief or
 *   the conversation;
 * - a link to a real page of the site (decision 22): with a LinkContext
 *   whose index is a LinkLookup, an href that points at a row of the
 *   draft's site (LinkCandidates::linkKey()) that may be linked to
 *   (Linkable: published, with an address, not noindex, not a utility
 *   page or the home page) and isn't the page itself. It is written as
 *   the dialect writes it (InlineLinks::inlineHref(): `entry::abc` becomes
 *   `statamic://entry::abc`), and counts as a link the page has.
 *
 * Anything else (an internal-looking address the writer made up, a
 * mangled `statamic://` reference, a page that's a draft) becomes a
 * `#gw-link:` marker with a hint from its words, which Finish this page
 * then asks the editor to choose. A link an editor removed (Remove link)
 * is taken out, words kept, if the writer puts it back. No model.
 */
final class LinkGuard
{
    /** A markdown link, not an image: words, href, optional title. */
    private const LINK = '/(?<!!)\[([^\[\]\n]*)\]\(\s*(<[^>\n]*>|[^()\s]*)(?:\s+"[^"\n]*")?\s*\)/u';

    /**
     * @param  array<mixed>  $data  The new draft's data.
     * @param  array<mixed>|null  $previous  The previous draft's data; null on a first draft.
     * @param  list<string>  $sources  The brief's answers and the conversation, as text.
     * @param  LinkContext|null  $site  Where the draft is going and the site's link index: links to its real pages are kept.
     * @return array{0: array<mixed>, 1: list<array{href: string, words: string, to: string}>, 2: list<array{href: string, words: string, to: string, title: string}>} The data, the links made markers (or taken out), and the links to real pages kept.
     */
    public function guard(array $data, ?array $previous, array $sources, SeoState $state = new SeoState, ?LinkContext $site = null, ?DateTimeImmutable $now = null): array
    {
        $kept = [];

        foreach (self::hrefs($previous ?? []) as $href) {
            $kept[$href] = true;
            $kept[LinkCandidates::linkKey($href) ?? $href] = true;
        }

        foreach ($state->links as $link) {
            $kept[$link['href']] = true;
            $kept[LinkCandidates::linkKey($link['href']) ?? $link['href']] = true;
        }

        $haystack = mb_strtolower(implode("\n", $sources));
        $changes = [];
        $real = [];

        $walk = function (mixed $value) use (&$walk, $kept, $haystack, $state, $site, $now, &$changes, &$real): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }

            if (! is_string($value) || ! str_contains($value, '](')) {
                return $value;
            }

            return (string) preg_replace_callback(self::LINK, function (array $match) use ($kept, $haystack, $state, $site, $now, &$changes, &$real) {
                $words = $match[1];
                $href = trim($match[2], '<> ');

                if (self::allowed($href, $kept, $haystack)) {
                    return $match[0];
                }

                if (! $state->wasRemoved($href) && trim($words) !== '' && ($page = self::page($href, $site, $now)) !== null) {
                    $to = $page[0] === $href ? $match[0] : '['.$words.']('.$page[0].')';
                    $real[] = ['href' => $href, 'words' => $words, 'to' => $to, 'title' => $page[1]];

                    return $to;
                }

                $to = $state->wasRemoved($href) || trim($words) === '' ? $words : '['.$words.']('.Markers::link($words).')';
                $changes[] = ['href' => $href, 'words' => $words, 'to' => $to];

                return $to;
            }, $value);
        };

        return [$walk($data), $changes, $real];
    }

    /**
     * A real page of the site an href points at, as [the href the dialect
     * writes for it, its title]; null when the index can't look links up,
     * no row matches, or the page can't be linked to.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function page(string $href, ?LinkContext $site, ?DateTimeImmutable $now): ?array
    {
        if ($site === null || ! $site->index instanceof LinkLookup) {
            return null;
        }

        $row = $site->index->linkRow($href, $site->site);

        if ($row === null || ($site->except !== null && $row->entry->is($site->except)) || Linkable::excluded($row, $now) !== null) {
            return null;
        }

        $inline = $site->links->inlineHref($row->digest());

        return $inline === null || trim($inline) === '' ? null : [trim($inline), $row->title];
    }

    /**
     * Every href in some draft data.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    public static function hrefs(array $data): array
    {
        $hrefs = [];

        array_walk_recursive($data, function (mixed $value) use (&$hrefs) {
            if (is_string($value) && str_contains($value, '](') && preg_match_all(self::LINK, $value, $matches) > 0) {
                foreach ($matches[2] as $href) {
                    $hrefs[] = trim($href, '<> ');
                }
            }
        });

        return array_values(array_unique($hrefs));
    }

    /**
     * @param  array<string, true>  $kept
     */
    private static function allowed(string $href, array $kept, string $haystack): bool
    {
        if ($href === '' || str_contains($href, Markers::LINK_PREFIX) || str_starts_with($href, '#')) {
            return true;
        }

        if (isset($kept[$href]) || isset($kept[LinkCandidates::linkKey($href) ?? "\0"])) {
            return true;
        }

        $outside = preg_match('#^(?:https?://|mailto:|tel:)#i', $href) === 1;
        $bare = mb_strtolower((string) preg_replace('#^(?:https?://(?:www\.)?|mailto:|tel:)#i', '', rtrim($href, '/')));

        return $outside && $bare !== '' && str_contains($haystack, $bare);
    }
}
