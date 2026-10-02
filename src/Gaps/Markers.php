<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * The marks Ghostwriter leaves in content where an editor must finish it,
 * and how they are found. They are visible on purpose: an editor who never
 * opens the guide still sees them.
 *
 * - **A fact to add:** `[[ask: adult ticket price]]`, in any text. Written
 *   strictly by ask(); found leniently, so `[[ ASK:x ]]` from a model or a
 *   hand edit still counts. The name `ask` is reserved: no vocabulary
 *   placeholder may use it (a test says so), and vocabulary placeholders
 *   never have a colon.
 * - **A link to choose:** a real link whose target is `#gw-link:` and a
 *   hint, `[Talk to us](#gw-link:contact-page)`. If it is published it goes
 *   nowhere: a same-page fragment. A link field that must hold a whole
 *   address gets `https://example.com/#gw-link:contact-page` (linkUrl()).
 * - Images have no marker of their own: the striped placeholder and a stock
 *   stand-in are recognised by their asset.
 *
 * The keywords `ask` and `gw-link` are never translated; the hint is in the
 * site's language. patterns() gives the same patterns to the front end
 * (resources/gaps/patterns.json), so nothing is retyped in JavaScript.
 */
final class Markers
{
    public const ASK = 'ask';

    public const LINK = 'gw-link';

    /** What a link's target starts with when it is still to choose. */
    public const LINK_PREFIX = '#gw-link:';

    /** The longest hint ask() writes. */
    public const MAX_HINT = 120;

    /** A fact to add, found leniently. Group 1 is the hint. */
    public const ASK_PATTERN = '/\[\[\s*ask\s*:\s*([^\[\]\s][^\[\]\n]{0,199}?)\s*\]\]/iu';

    /**
     * A markdown link still to choose. Group 1 is its words, group 2 the
     * hint. The `https://example.com/` form of a link field counts too.
     */
    public const LINK_PATTERN = '/\[([^\[\]\n]*)\]\(\s*<?(?:https?:\/\/example\.com\/?)?#gw-link:([^\s)>]*)>?(?:\s+"[^"\n]*")?\s*\)/iu';

    /** The sentinel in any address. Group 1 is the hint. */
    public const SENTINEL_PATTERN = '/#gw-link:([A-Za-z0-9._~%-]*)/u';

    /**
     * A vocabulary placeholder left in text (`[[item]]`): a prompt override
     * with a typo can let one reach a draft. No colon, so never an ask.
     */
    public const LEFTOVER_PATTERN = '/\[\[([a-z_]{2,30})\]\]/';

    /**
     * Text that looks like it is waiting for something. Each one alone, so
     * the case rules can differ: `TBC` in capitals, `lorem ipsum` in any.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDER_TEXT_PATTERNS = [
        'ellipsis' => '/\[(?:\.\.\.|…)\]/u',
        'lorem' => '/\blorem ipsum\b/iu',
        'initials' => '/\b(?:TBC|TBD|TBA|TODO|XXX+)\b/u',
        'questions' => '/\?{3,}/u',
        'bracketed' => '/\[(?:insert|add|check|tk)\b[^\[\]\n]{0,160}\](?!\()/iu',
    ];

    /** Near misses a model may write, put right by normalise(). Group 1 is the hint. */
    private const NEAR_MISSES = [
        '/(?<!\[)\[\s*ask\s*:\s*([^\[\]\s][^\[\]\n]{0,199}?)\s*\](?![\]\(])/iu',
        '/\[\[\s*ask\s*[-–—]\s*([^\[\]\s][^\[\]\n]{0,199}?)\s*\]\]/iu',
    ];

    /**
     * The mark for a fact to add: `[[ask: adult ticket price]]`. The hint
     * loses brackets and line breaks and is kept to MAX_HINT characters.
     */
    public static function ask(string $hint): string
    {
        return '[[ask: '.self::cleanHint($hint).']]';
    }

    /** The target of an inline link still to choose: `#gw-link:contact-page`. */
    public static function link(string $hint): string
    {
        return self::LINK_PREFIX.self::slug($hint);
    }

    /**
     * The same, as a whole address, for link fields that validate one:
     * `https://example.com/#gw-link:contact-page`.
     */
    public static function linkUrl(string $hint): string
    {
        return LinkDialect::PLACEHOLDER_URL.'/'.self::link($hint);
    }

    /** A hint as a link's target takes it: a few hyphenated words. */
    public static function slug(string $hint): string
    {
        $slug = Slug::make($hint, 60);

        return $slug !== '' ? $slug : 'link';
    }

    /** Whether a link's target (or a link field's value) is still to choose. */
    public static function isLinkSentinel(mixed $href): bool
    {
        return is_string($href) && preg_match(self::SENTINEL_PATTERN, $href) === 1;
    }

    /** The hint in a sentinel target, or null when it isn't one. */
    public static function linkHint(mixed $href): ?string
    {
        return is_string($href) && preg_match(self::SENTINEL_PATTERN, $href, $match) === 1 ? rawurldecode($match[1]) : null;
    }

    /**
     * Every fact to add in some text, in order, each with its hint, the
     * marker as written, its byte offset and which occurrence of that hint
     * it is (0 for the first).
     *
     * @return list<array{hint: string, match: string, offset: int, occurrence: int}>
     */
    public static function asks(string $text): array
    {
        return self::found(self::ASK_PATTERN, $text, 1);
    }

    /**
     * Every link still to choose in some markdown, in order, with its
     * words and hint.
     *
     * @return list<array{hint: string, words: string, match: string, offset: int, occurrence: int}>
     */
    public static function links(string $markdown): array
    {
        $found = [];
        $seen = [];

        if (preg_match_all(self::LINK_PATTERN, $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $hint = rawurldecode($match[2][0]);
                $key = self::normaliseHint($hint);
                $found[] = ['hint' => $hint, 'words' => $match[1][0], 'match' => $match[0][0], 'offset' => $match[0][1], 'occurrence' => $seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 0];
            }
        }

        return $found;
    }

    /**
     * Vocabulary placeholders left in some text.
     *
     * @return list<array{hint: string, match: string, offset: int, occurrence: int}>
     */
    public static function leftovers(string $text): array
    {
        return self::found(self::LEFTOVER_PATTERN, $text, 1);
    }

    /**
     * Text that looks like it is waiting for something (`TBC`, `[insert
     * date]`), in order. The hint is the text itself.
     *
     * @return list<array{hint: string, match: string, offset: int, occurrence: int}>
     */
    public static function placeholderText(string $text): array
    {
        $found = [];

        foreach (self::PLACEHOLDER_TEXT_PATTERNS as $pattern) {
            foreach (self::found($pattern, $text, 0) as $match) {
                $found[$match['offset']] = $match;
            }
        }

        ksort($found);
        $seen = [];

        return array_values(array_map(function (array $match) use (&$seen) {
            $key = self::normaliseHint($match['hint']);
            $match['occurrence'] = $seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 0;

            return $match;
        }, $found));
    }

    /** Whether some text holds a fact to add or a link to choose. */
    public static function has(string $text): bool
    {
        return preg_match(self::ASK_PATTERN, $text) === 1 || preg_match(self::SENTINEL_PATTERN, $text) === 1;
    }

    /**
     * Facts to add written the strict way: `[[ask: hint]]`, from the
     * lenient forms and the near misses a model may write (`[ask: x]`,
     * `[[Ask - x]]`).
     */
    public static function normalise(string $text): string
    {
        if (stripos($text, 'ask') === false) {
            return $text;
        }

        foreach ([...self::NEAR_MISSES, self::ASK_PATTERN] as $pattern) {
            $text = (string) preg_replace_callback($pattern, fn (array $match) => self::ask($match[1]), $text);
        }

        return $text;
    }

    /** Some text with its facts to add taken out, for a slug or a file name. */
    public static function withoutAsks(string $text): string
    {
        return (string) preg_replace(self::ASK_PATTERN, ' ', $text);
    }

    /**
     * The sentence a marker sits in, on one line, at most $max characters:
     * for the guide's message.
     */
    public static function excerpt(string $text, int $offset, int $length, int $max = 240): string
    {
        $before = substr($text, 0, $offset);
        $after = substr($text, $offset + $length);

        $start = 0;

        if (preg_match_all('/[.!?](?=\s)|\n/u', $before, $ends, PREG_OFFSET_CAPTURE) > 0) {
            [$mark, $at] = $ends[0][count($ends[0]) - 1];
            $start = $at + strlen($mark);
        }

        $end = strlen($after);

        if (preg_match('/[.!?](?=\s|$)|\n/u', $after, $stop, PREG_OFFSET_CAPTURE) === 1) {
            $end = $stop[0][1] + ($stop[0][0] === "\n" ? 0 : 1);
        }

        $sentence = substr($before, $start).substr($text, $offset, $length).substr($after, 0, $end);
        $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));
        // List markers and headings are layout, not words.
        $sentence = (string) preg_replace('/^(?:[-*+]|\d+\.|#{1,6})\s+/u', '', $sentence);

        return Slug::clip($sentence, $max);
    }

    /** A hint as gap IDs compare it: lower case, single spaces. */
    public static function normaliseHint(string $hint): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $hint)));
    }

    /**
     * The patterns as JavaScript takes them (`new RegExp(source, flags)`),
     * for resources/gaps/patterns.json.
     *
     * @return array<string, mixed>
     */
    public static function patterns(): array
    {
        $placeholderText = [];

        foreach (self::PLACEHOLDER_TEXT_PATTERNS as $name => $pattern) {
            $placeholderText[$name] = self::js($pattern);
        }

        return [
            'ask' => self::js(self::ASK_PATTERN),
            'link' => self::js(self::LINK_PATTERN),
            'sentinel' => self::js(self::SENTINEL_PATTERN),
            'leftover' => self::js(self::LEFTOVER_PATTERN),
            'placeholderText' => $placeholderText,
            'linkPrefix' => self::LINK_PREFIX,
        ];
    }

    /** Where patterns() is kept for the front end. */
    public static function patternsFile(): string
    {
        return dirname(__DIR__, 2).'/resources/gaps/patterns.json';
    }

    /**
     * @return list<array{hint: string, match: string, offset: int, occurrence: int}>
     */
    private static function found(string $pattern, string $text, int $group): array
    {
        $found = [];
        $seen = [];

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $hint = trim($match[$group][0]);
                $key = self::normaliseHint($hint);
                $found[] = ['hint' => $hint, 'match' => $match[0][0], 'offset' => $match[0][1], 'occurrence' => $seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 0];
            }
        }

        return $found;
    }

    private static function cleanHint(string $hint): string
    {
        $hint = trim((string) preg_replace('/[\[\]\s]+/u', ' ', $hint));

        return Slug::clip($hint !== '' ? $hint : 'something to add', self::MAX_HINT);
    }

    /**
     * A PCRE pattern as a JavaScript source and flags: `g` always, `i` and
     * `u` where the PCRE has them.
     *
     * @return array{source: string, flags: string}
     */
    private static function js(string $pattern): array
    {
        $end = strrpos($pattern, '/');
        $source = str_replace('\/', '/', substr($pattern, 1, (int) $end - 1));
        $modifiers = substr($pattern, (int) $end + 1);

        return ['source' => $source, 'flags' => 'g'.(str_contains($modifiers, 'i') ? 'i' : '').(str_contains($modifiers, 'u') ? 'u' : '')];
    }
}
