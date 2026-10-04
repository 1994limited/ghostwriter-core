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
 * - **A count to check:** `[[check: 3 areas | from: Northumberland, Durham
 *   and the Tyne Valley]]`. Ghostwriter counted a list the editor gave
 *   (ListCounter, never a model) and the editor confirms the count before
 *   the page goes live. The value is what the page will say; `from:` is
 *   the list on one line (a bulleted list's items joined by "; "). Written
 *   strictly by check(); found leniently, like an ask.
 * - Images have no marker of their own: the striped placeholder and a stock
 *   stand-in are recognised by their asset.
 *
 * The keywords `ask`, `check`, `from` and `gw-link` are never translated; the hint is in the
 * site's language. patterns() gives the same patterns to the front end
 * (resources/gaps/patterns.json), so nothing is retyped in JavaScript.
 */
final class Markers
{
    public const ASK = 'ask';

    public const LINK = 'gw-link';

    public const CHECK = 'check';

    /** What a link's target starts with when it is still to choose. */
    public const LINK_PREFIX = '#gw-link:';

    /** The longest hint ask() writes. */
    public const MAX_HINT = 120;

    /** A fact to add, found leniently. Group 1 is the hint. */
    public const ASK_PATTERN = '/\[\[\s*ask\s*:\s*([^\[\]\s][^\[\]\n]{0,199}?)\s*\]\]/iu';

    /**
     * A count to check, found leniently. Group 1 is the value ("3 areas"),
     * group 2 the list it was counted from.
     */
    public const CHECK_PATTERN = '/\[\[\s*check\s*:\s*([^\[\]|\n]{1,120}?)\s*\|\s*from\s*:\s*([^\[\]\n]{1,300}?)\s*\]\]/iu';

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
        'bracketed' => '/(?<!\[)\[(?:insert|add|check|tk)\b[^\[\]\n]{0,160}\](?![(\]])/iu',
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

    /**
     * The mark for a count to check: `[[check: 3 areas | from:
     * Northumberland, Durham and the Tyne Valley]]`. Brackets, bars and
     * line breaks are taken out of both halves.
     */
    public static function check(string $value, string $list): string
    {
        $clean = fn (string $text, int $max) => Slug::clip(trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']', '|'], ['(', ')', '/'], $text))), $max);

        return '[[check: '.$clean($value, 120).' | from: '.$clean($list, 300).']]';
    }

    /**
     * Every count to check in some text, in order, with its value (also
     * its hint), the list it was counted from, the marker as written, its
     * byte offset and which occurrence of that value it is.
     *
     * @return list<array{hint: string, value: string, list: string, match: string, offset: int, occurrence: int}>
     */
    public static function checks(string $text): array
    {
        $found = [];
        $seen = [];

        if (stripos($text, 'check') !== false && preg_match_all(self::CHECK_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $value = trim($match[1][0]);
                $key = self::normaliseHint($value);
                $found[] = ['hint' => $value, 'value' => $value, 'list' => trim($match[2][0]), 'match' => $match[0][0], 'offset' => $match[0][1], 'occurrence' => $seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 0];
            }
        }

        return $found;
    }

    /**
     * Some text with one count to check resolved: the marker (as written,
     * the given occurrence of it) replaced by $value. "Looks right" passes
     * the marker's own value, "Change it" the editor's, "Remove it" ''.
     * The text is unchanged when the marker isn't there.
     */
    public static function resolveCheck(string $text, string $match, string $value, int $occurrence = 0): string
    {
        return self::replace($text, $match, $value, $occurrence);
    }

    /**
     * Some text with one fact to add resolved: the marker (as written, the
     * given occurrence of it) replaced by the editor's answer, exactly as
     * they typed it (only the line ends are taken off). '' takes the marker
     * out. The text is unchanged when the marker isn't there. No model.
     */
    public static function resolveAsk(string $text, string $match, string $answer, int $occurrence = 0): string
    {
        return self::replace($text, $match, trim(str_replace("\r", '', $answer), "\n"), $occurrence);
    }

    /**
     * Some markdown with one link to choose resolved: the link (as written,
     * the given occurrence of it, as links() gives `match`) pointed at
     * $href, its words kept. '' unlinks it, leaving the words. A link
     * field's whole value (`https://example.com/#gw-link:contact-page`) is
     * resolved by replacing the value, not here.
     */
    public static function resolveLink(string $markdown, string $match, string $href, int $occurrence = 0): string
    {
        if (preg_match(self::LINK_PATTERN, $match, $parts) !== 1) {
            return $markdown;
        }

        $words = $parts[1];
        $href = trim(str_replace(['(', ')', ' ', "\n"], ['%28', '%29', '%20', ''], $href));

        return self::replace($markdown, $match, $href === '' ? $words : '['.$words.']('.$href.')', $occurrence, removing: false);
    }

    /**
     * $text with the $occurrence-th $match replaced by $value. An empty
     * $value removes it without leaving a double space behind.
     */
    private static function replace(string $text, string $match, string $value, int $occurrence, bool $removing = true): string
    {
        if ($match === '') {
            return $text;
        }

        $at = -1;

        for ($i = 0; $i <= $occurrence; $i++) {
            $at = strpos($text, $match, $at + 1);

            if ($at === false) {
                return $text;
            }
        }

        $before = substr($text, 0, $at);
        $after = substr($text, $at + strlen($match));

        if ($value === '' && $removing) {
            // Removing it leaves no double space behind.
            [$before, $after] = [rtrim($before, ' '), ltrim($after, ' ')];
            $join = $before !== '' && $after !== '' && preg_match('/^[\s.,;:!?)]/u', $after) !== 1 ? ' ' : '';

            return $before.$join.$after;
        }

        return $before.$value.$after;
    }

    /** Some text with each count to check as the plain value it marks: what the page says once it is confirmed. */
    public static function withoutChecks(string $text): string
    {
        return stripos($text, 'check') === false ? $text : (string) preg_replace_callback(self::CHECK_PATTERN, fn (array $match) => trim($match[1]), $text);
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

    /** Whether some text holds a fact to add, a count to check or a link to choose. */
    public static function has(string $text): bool
    {
        return preg_match(self::ASK_PATTERN, $text) === 1 || preg_match(self::SENTINEL_PATTERN, $text) === 1 || preg_match(self::CHECK_PATTERN, $text) === 1;
    }

    /**
     * Facts to add written the strict way: `[[ask: hint]]`, from the
     * lenient forms and the near misses a model may write (`[ask: x]`,
     * `[[Ask - x]]`). Counts to check are written strictly too.
     */
    public static function normalise(string $text): string
    {
        if (stripos($text, 'check') !== false) {
            $text = (string) preg_replace_callback(self::CHECK_PATTERN, fn (array $match) => self::check($match[1], $match[2]), $text);
        }

        if (stripos($text, 'ask') === false) {
            return $text;
        }

        foreach ([...self::NEAR_MISSES, self::ASK_PATTERN] as $pattern) {
            $text = (string) preg_replace_callback($pattern, fn (array $match) => self::ask($match[1]), $text);
        }

        return $text;
    }

    /**
     * Some text with its facts to add taken out, and its counts to check
     * as their values, for a slug, a file name or a fact check (a count is
     * checked by the editor, not against a source).
     */
    public static function withoutAsks(string $text): string
    {
        return (string) preg_replace(self::ASK_PATTERN, ' ', self::withoutChecks($text));
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
            'check' => self::js(self::CHECK_PATTERN),
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
