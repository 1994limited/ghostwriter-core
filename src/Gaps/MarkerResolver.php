<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Resolving one gap marker where it is stored, from what the editor saw: a
 * chip in the page preview or the Text tab says only its kind and hint
 * (and, for a count, its list), not where the marker is. This finds it in
 * a draft's values, in order, and replaces it with the editor's answer.
 * No model: an answer goes in exactly as it was typed.
 *
 * ```php
 * $texts = MarkerResolver::leaves($draft->data);           // every string, with its path
 * $texts[] = ['path' => ['extras', 'x1.2'], 'text' => $item->text];
 * $found = MarkerResolver::find($texts, 'ask', 'adult ticket price');
 * $new = MarkerResolver::apply($found['text'], $found, '£12');
 * ```
 *
 * - `ask`: replaced by the answer (Markers::resolveAsk()); '' takes it out.
 * - `check`: replaced by the value ("Looks right" and "Change it"), or
 *   taken out with '' ("Remove it"): Markers::resolveCheck().
 * - `link`: a markdown link pointed at the chosen address, its words kept
 *   (Markers::resolveLink()); a value that is only a sentinel (a link
 *   field's `https://example.com/#gw-link:contact-page`) is replaced by
 *   what the addon gives, as a whole.
 *
 * Hints are compared as gap IDs compare them (lower case, single spaces),
 * and a link's hint with its hyphens as spaces, as the chips show it. The
 * $occurrence is which of the markers with that kind and hint the chip
 * was, counting through every text in order.
 */
final class MarkerResolver
{
    public const KINDS = ['ask', 'check', 'link'];

    /**
     * Every string in some nested data, depth first, with its path.
     *
     * @param  array<int|string, mixed>  $data
     * @param  list<int|string>  $prefix
     * @return list<array{path: list<int|string>, text: string}>
     */
    public static function leaves(array $data, array $prefix = []): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            $path = [...$prefix, $key];

            if (is_string($value)) {
                $found[] = ['path' => $path, 'text' => $value];
            } elseif (is_array($value)) {
                array_push($found, ...self::leaves($value, $path));
            }
        }

        return $found;
    }

    /**
     * The marker a chip stands for, or null when no text holds one like it.
     * With fewer of them than $occurrence + 1, the last is taken: the
     * preview may show them in another order, never more of them.
     *
     * @param  list<array{path: list<int|string>, text: string}>  $texts
     * @return array{path: list<int|string>, text: string, kind: string, match: string, occurrence: int, whole: bool}|null
     */
    public static function find(array $texts, string $kind, string $hint, ?string $list = null, int $occurrence = 0): ?array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }

        $want = self::key($kind, $hint);
        $candidates = [];

        foreach ($texts as $text) {
            foreach (self::markers($kind, $text['text']) as $marker) {
                if (self::key($kind, $marker['hint']) === $want) {
                    $candidates[] = [...$marker, 'path' => $text['path'], 'text' => $text['text']];
                }
            }
        }

        // A count's list narrows it down, when it still matches one.
        if ($kind === 'check' && $list !== null && trim($list) !== '') {
            $sameList = array_values(array_filter($candidates, fn (array $marker) => self::key('check', $marker['list'] ?? '') === self::key('check', $list)));
            $candidates = $sameList !== [] ? $sameList : $candidates;
        }

        if ($candidates === []) {
            return null;
        }

        $marker = $candidates[min(max(0, $occurrence), count($candidates) - 1)];

        return [
            'path' => $marker['path'],
            'text' => $marker['text'],
            'kind' => $kind,
            'match' => $marker['match'],
            // Which of the identical markers in this text it is, for the resolvers.
            'occurrence' => substr_count(substr($marker['text'], 0, $marker['offset']), $marker['match']),
            'whole' => $marker['whole'] ?? false,
        ];
    }

    /**
     * The text find() found, with that marker resolved to $value.
     *
     * @param  array{kind: string, match: string, occurrence: int, whole: bool}  $found
     */
    public static function apply(string $text, array $found, string $value): string
    {
        return match ($found['kind']) {
            'ask' => Markers::resolveAsk($text, $found['match'], $value, $found['occurrence']),
            'check' => Markers::resolveCheck($text, $found['match'], trim($value), $found['occurrence']),
            'link' => $found['whole'] ? $value : Markers::resolveLink($text, $found['match'], $value, $found['occurrence']),
            default => $text,
        };
    }

    /**
     * @return list<array{hint: string, match: string, offset: int, list?: string, whole?: bool}>
     */
    private static function markers(string $kind, string $text): array
    {
        if ($kind === 'ask') {
            return Markers::asks($text);
        }

        if ($kind === 'check') {
            return Markers::checks($text);
        }

        $links = Markers::links($text);

        // A value that is only a sentinel: a link field's.
        if ($links === [] && preg_match('/\A\s*(?:https?:\/\/example\.com\/?)?#gw-link:([A-Za-z0-9._~%-]*)\s*\z/u', $text, $match) === 1) {
            return [['hint' => rawurldecode($match[1]), 'match' => $text, 'offset' => 0, 'whole' => true]];
        }

        return $links;
    }

    private static function key(string $kind, string $hint): string
    {
        $hint = (string) preg_replace('/[\x{E0000}-\x{E007F}]/u', '', $hint);

        return Markers::normaliseHint($kind === 'link' ? (string) preg_replace('/[-_]+/', ' ', $hint) : $hint);
    }
}
