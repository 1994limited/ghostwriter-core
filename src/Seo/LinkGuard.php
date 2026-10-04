<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * The writer's "never use a made-up address" rule, in code (SEO layer
 * §5.2). After every writer turn, each link in the new draft must be one
 * of:
 *
 * - a link the previous draft had (the same href, or the same page);
 * - a link the SEO pass added;
 * - a `#gw-link:` marker (a link only the editor can choose);
 * - an outside address, `mailto:` or `tel:` that appears in the brief or
 *   the conversation.
 *
 * Anything else (an internal-looking address the writer made up, a
 * mangled `statamic://` reference) becomes a `#gw-link:` marker with a
 * hint from its words, which Finish this page then asks the editor to
 * choose. A link an editor removed (Remove link) is taken out, words kept,
 * if the writer puts it back. No model.
 */
final class LinkGuard
{
    /** A markdown link, not an image: words, href, optional title. */
    private const LINK = '/(?<!!)\[([^\[\]\n]*)\]\(\s*(<[^>\n]*>|[^()\s]*)(?:\s+"[^"\n]*")?\s*\)/u';

    /**
     * @param  array<mixed>  $data  The new draft's data.
     * @param  array<mixed>|null  $previous  The previous draft's data; null on a first draft.
     * @param  list<string>  $sources  The brief's answers and the conversation, as text.
     * @return array{0: array<mixed>, 1: list<array{href: string, words: string, to: string}>} The data, and what changed.
     */
    public function guard(array $data, ?array $previous, array $sources, SeoState $state = new SeoState): array
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

        $walk = function (mixed $value) use (&$walk, $kept, $haystack, $state, &$changes): mixed {
            if (is_array($value)) {
                return array_map($walk, $value);
            }

            if (! is_string($value) || ! str_contains($value, '](')) {
                return $value;
            }

            return (string) preg_replace_callback(self::LINK, function (array $match) use ($kept, $haystack, $state, &$changes) {
                $words = $match[1];
                $href = trim($match[2], '<> ');

                if (self::allowed($href, $kept, $haystack)) {
                    return $match[0];
                }

                $to = $state->wasRemoved($href) || trim($words) === '' ? $words : '['.$words.']('.Markers::link($words).')';
                $changes[] = ['href' => $href, 'words' => $words, 'to' => $to];

                return $to;
            }, $value);
        };

        return [$walk($data), $changes];
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
