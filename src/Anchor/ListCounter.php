<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Finds plain lists in text and counts their items, with no model: a
 * derived count ("3 areas") is always counted here, never by a model.
 *
 * What counts as a plain list:
 *
 * - **Inline:** three or more items separated by commas, with a final
 *   "and" or "or" (an Oxford comma or not): "Northumberland, Durham and
 *   the Tyne Valley" is 3. A lead-in before the first item is left out
 *   ("We cover Northumberland, …" counts the same).
 * - **Bulleted:** two or more lines starting `-`, `*`, `+`, `•` or `1.`,
 *   counted at the outer level only: a nested list belongs to its parent
 *   item.
 *
 * What is skipped, so nothing is counted that a person wouldn't count:
 *
 * - **Open lists:** "etc.", "and so on", "and more", "such as",
 *   "including", "e.g.", "for example" and the like, anywhere in the
 *   sentence or the bulleted list;
 * - **ranges:** an item such as "Wednesday to Friday" or "9–5";
 * - **prose:** an item of more than six words, or one with a pronoun or a
 *   verb such as "is" or "have" in it ("When it rains, we close and the
 *   garden rests"), and two items with no comma ("salt and pepper");
 * - **ambiguous lists:** "and" and "or" mixed, or an "and" inside an item
 *   with no Oxford comma to tell where the last item starts.
 */
final class ListCounter
{
    /** The most words an inline item may have. */
    public const MAX_WORDS = 6;

    /** The longest list a marker carries. */
    public const MAX_LENGTH = 300;

    /** Words that make a list open-ended, or mark it as examples. */
    private const OPEN = '/(?:\betc\b|\bet cetera\b|\band so on\b|\band so forth\b|\b(?:and|or) more\b|\band (?:many )?others\b|\bamong others\b|\band the like\b|\bor similar\b|\bsuch as\b|\bincluding\b|\bincludes?\b|\be\.\s?g\b|\bfor (?:example|instance)\b|\bamong them\b|…|\.\.\.)/iu';

    /** Words that make an item prose rather than a name or a thing. */
    private const PROSE = ['i', 'we', 'you', 'they', 'he', 'she', 'it', 'me', 'us', 'them', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'has', 'have', 'had', 'will', 'would', 'can', 'could', 'should', 'shall', 'may', 'might', 'must', 'do', 'does', 'did', 'that', 'which', 'who', 'when', 'where', 'if', 'because', 'but', 'so', 'then', 'there', 'than', 'while', 'although', 'though', 'not', 'don', 'isn', 'aren', 'won', 't', 'll', 've', 're'];

    /** A range: "Wednesday to Friday", "9–5", "May through July". */
    private const RANGE = '/\d\s*[-–—]\s*\d|\b(?:to|through|thru|until|till)\b/iu';

    private const BULLET = '/^([ \t]*)(?:[-*+•]|\d{1,3}[.)])[ \t]+(\S.*)$/u';

    private const NUMBER_WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20];

    /**
     * Every plain list in some text, in order: bulleted lists, then the
     * inline lists in the lines that aren't bullets.
     *
     * @return list<CountedList>
     */
    public static function find(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $found = [];
        $prose = [];
        $i = 0;

        while ($i < count($lines)) {
            if (preg_match(self::BULLET, $lines[$i]) === 1) {
                [$list, $i] = self::bullets($lines, $i);

                if ($list !== null) {
                    $found[] = $list;
                }

                continue;
            }

            $prose[] = $lines[$i];
            $i++;
        }

        foreach ($prose as $line) {
            foreach (self::segments($line) as $segment) {
                $list = self::inline($segment);

                if ($list !== null) {
                    $found[] = $list;
                }
            }
        }

        return $found;
    }

    /**
     * The one plain list a quote holds, or null when it holds none, or
     * more than one.
     */
    public static function count(string $quote): ?CountedList
    {
        $lists = self::find($quote);

        return count($lists) === 1 ? $lists[0] : null;
    }

    /**
     * The list a marker's `from:` names: a one-line list (CountedList::oneLine()),
     * so either an inline list or items joined by semicolons.
     */
    public static function fromMarker(string $from): ?CountedList
    {
        $from = trim($from);

        if (str_contains($from, ';') && ! str_contains($from, "\n")) {
            $items = array_values(array_filter(array_map('trim', explode(';', $from)), fn (string $item) => $item !== ''));

            return count($items) >= 2 && preg_match(self::OPEN, $from) !== 1 ? new CountedList($items, $from, CountedList::BULLETS) : null;
        }

        return self::count($from);
    }

    /**
     * The whole numbers some text states, in digits or in words up to
     * twenty, each with where it is. Part of a bigger figure (a price, a
     * percentage, a decimal, a range) doesn't count.
     *
     * @return list<array{value: int, match: string, offset: int}>
     */
    public static function numbers(string $text): array
    {
        $words = implode('|', array_keys(self::NUMBER_WORDS));
        $found = [];

        if (preg_match_all('/(?<![\p{L}\p{N}£$€¥.,%-])(\d{1,6}|'.$words.')(?![\p{N}%]|[.,]\d|\s?[-–—]\s?\d|\s?(?:per\s?cent|percent)\b)(?!\p{L})/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $word = mb_strtolower($match[1][0]);
                $found[] = ['value' => self::NUMBER_WORDS[$word] ?? (int) $word, 'match' => $match[1][0], 'offset' => $match[1][1]];
            }
        }

        return $found;
    }

    /** The first whole number in some text ("3 areas" is 3), or null. */
    public static function numberIn(string $text): ?int
    {
        return self::numbers($text)[0]['value'] ?? null;
    }

    /**
     * A bulleted list starting at line $i, and the line after it.
     *
     * @param  list<string>  $lines
     * @return array{0: CountedList|null, 1: int}
     */
    private static function bullets(array $lines, int $i): array
    {
        $indent = self::depth(preg_match(self::BULLET, $lines[$i], $first) === 1 ? $first[1] : '');
        $items = [];
        $open = false;
        $end = $i;

        for ($j = $i; $j < count($lines); $j++) {
            $line = $lines[$j];

            if (trim($line) === '') {
                // One blank line may sit between items; two, or prose after it, end the list.
                if (preg_match(self::BULLET, $lines[$j + 1] ?? '') !== 1) {
                    break;
                }

                continue;
            }

            $depth = self::depth((string) preg_replace('/^([ \t]*).*$/su', '$1', $line));

            if ($depth <= $indent && preg_match(self::BULLET, $line, $match) === 1) {
                $item = self::clean($match[2]);
                $open = $open || preg_match(self::OPEN, $item) === 1;
                $items[] = $item;
            } elseif ($depth <= $indent) {
                break;
            }

            // A nested item or a continuation line belongs to the item above.
            $end = $j;
        }

        $text = rtrim(implode("\n", array_slice($lines, $i, $end - $i + 1)));
        $list = ! $open && count($items) >= 2 ? new CountedList($items, $text, CountedList::BULLETS) : null;

        return [$list, $end + 1];
    }

    private static function depth(string $indent): int
    {
        return strlen(str_replace("\t", '    ', $indent));
    }

    /**
     * A line's sentences and clauses, each a place an inline list may be.
     *
     * @return list<string>
     */
    private static function segments(string $line): array
    {
        $line = trim((string) preg_replace('/^(?:#{1,6}|>)\s*/u', '', trim($line)));
        // Sentences end at . ! ? or ;, but not at "e.g." or "i.e.".
        $segments = preg_split('/(?<![\s.]e\.g\.)(?<!^e\.g\.)(?<![\s.]i\.e\.)(?<!^i\.e\.)(?<=[.!?;])\s+|\s+[–—]\s+|\(|\)/iu', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $segments), fn (string $segment) => $segment !== ''));
    }

    /** The inline list a sentence is, or ends with, or null. */
    private static function inline(string $segment): ?CountedList
    {
        if (! str_contains($segment, ',') || preg_match(self::OPEN, $segment) === 1) {
            return null;
        }

        // After a colon, the list is what follows it.
        if (preg_match('/:\s+(?=\S)/u', $segment, $colon, PREG_OFFSET_CAPTURE) === 1) {
            $segment = substr($segment, $colon[0][1] + strlen($colon[0][0]));
        }

        $segment = rtrim($segment, " \t.!?;:");
        $parts = array_map('trim', explode(',', $segment));

        if (count($parts) < 2 || in_array('', $parts, true)) {
            return null;
        }

        $last = array_pop($parts);
        $conjunction = null;

        if (preg_match('/^(and|or)\s+(.+)$/iu', $last, $match) === 1) {
            // An Oxford comma: the last part is the last item.
            $conjunction = mb_strtolower($match[1]);
            $tail = [$match[2]];
        } else {
            if (preg_match_all('/\s(and|or)\s/iu', ' '.$last.' ', $joins) !== 1) {
                return null;
            }

            $conjunction = mb_strtolower($joins[1][0]);
            $tail = array_map('trim', preg_split('/\s(?:and|or)\s/iu', $last, 2) ?: []);

            // With no Oxford comma, an "and" inside an earlier item leaves it unclear.
            foreach (array_slice($parts, 1) as $part) {
                if (preg_match('/\s(?:and|or)\s/iu', ' '.$part.' ') === 1) {
                    return null;
                }
            }
        }

        foreach ($parts as $part) {
            if (preg_match('/^(?:and|or)\s/iu', $part) === 1) {
                return null;
            }
        }

        $rest = array_map([self::class, 'clean'], [...array_slice($parts, 1), ...$tail]);

        foreach ($rest as $item) {
            if (! self::isItem($item)) {
                return null;
            }

            if (preg_match('/\s(and|or)\s/iu', ' '.$item.' ', $inner) === 1 && mb_strtolower($inner[1]) !== $conjunction) {
                return null;
            }
        }

        $first = self::firstItem(self::clean($parts[0]), $rest);

        if ($first === null) {
            return null;
        }

        $items = [$first, ...$rest];

        if (count($items) < 3) {
            return null;
        }

        $start = strrpos($parts[0], $first);
        $text = $start === false ? $segment : substr($segment, $start);

        return new CountedList($items, trim($text), $conjunction === 'or' ? CountedList::OR : CountedList::AND);
    }

    /**
     * The first item, without any lead-in before it. When the other items
     * are names ("Durham", "the Tyne Valley") or start with a figure, so
     * does the first, and it is the longest run of its last words that is
     * one ("We cover Northumberland" is "Northumberland"). Otherwise it is
     * the longest run of its last words that is an item and no longer than
     * the longest other item.
     *
     * @param  list<string>  $rest
     */
    private static function firstItem(string $part, array $rest): ?string
    {
        $words = preg_split('/\s+/u', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $longest = max([1, ...array_map(fn (string $item) => count(preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY) ?: []), $rest)]);
        $names = array_filter($rest, fn (string $item) => ! self::isName($item)) === [];
        $figures = array_filter($rest, fn (string $item) => preg_match('/^\p{N}/u', $item) !== 1) === [];
        $most = $names || $figures ? self::MAX_WORDS : $longest;

        for ($take = min(count($words), $most); $take >= 1; $take--) {
            $candidate = implode(' ', array_slice($words, -$take));

            if (! self::isItem($candidate)) {
                continue;
            }

            if (($names && ! self::isName($candidate, true)) || ($figures && preg_match('/^\p{N}/u', $candidate) !== 1)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private static function isItem(string $item): bool
    {
        $words = NormalisedText::words($item);

        if ($words === [] || count(preg_split('/\s+/u', trim($item), -1, PREG_SPLIT_NO_EMPTY) ?: []) > self::MAX_WORDS) {
            return false;
        }

        if (preg_match(self::RANGE, $item) === 1) {
            return false;
        }

        return array_intersect($words, self::PROSE) === [];
    }

    /**
     * Whether an item starts with a capital, after any article: "the Tyne
     * Valley". With $every, every word must be capitalised but the small
     * ones ("Isle of Wight").
     */
    private static function isName(string $item, bool $every = false): bool
    {
        $item = (string) preg_replace('/^(?:the|a|an)\s+/iu', '', trim($item));

        if (preg_match('/^\p{Lu}/u', $item) !== 1) {
            return false;
        }

        foreach ($every ? preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [] : [] as $word) {
            if (preg_match('/^[\p{Lu}\p{N}]/u', $word) !== 1 && ! in_array(mb_strtolower($word), ['of', 'the', 'and', 'upon', 'on', 'in', 'de', 'la', 'le'], true)) {
                return false;
            }
        }

        return true;
    }

    /** An item without markdown emphasis or the punctuation at its ends. */
    private static function clean(string $item): string
    {
        $item = (string) preg_replace('/(\*\*|__|\*|_|`)(.+?)\1/u', '$2', $item);

        return trim($item, " \t.,;:!?\"'“”‘’");
    }
}
