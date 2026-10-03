<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Text as QuoteFinder compares it, with a map back to the original, so a
 * match in the normalised text is an offset in the text as written.
 *
 * Normalising:
 * - every run of whitespace (NBSP, narrow and figure spaces, tabs and
 *   newlines too) is one space, and the ends are trimmed;
 * - curly and straight quotes are the same (‘ ’ ‚ ‛ ′ → ', “ ” „ ‟ ″ → "),
 *   and so are hyphens, dashes and the minus sign (→ -); … is "...";
 * - invisible characters are dropped: soft hyphens, zero-width spaces and
 *   joiners, the BOM and Unicode tag characters (the preview's markers);
 * - with $markdown, markdown's inline syntax is skipped too: emphasis
 *   (`*`, `_`), code backticks, a link's brackets and its `(target)`, and
 *   a line's leading `#`, `>`, list bullet or number. So a quote of the
 *   words finds them inside `**bold**` or a link.
 *
 * Case is kept. Offsets and lengths are in characters (code points), as
 * mb_substr() counts them.
 */
final class NormalisedText
{
    private const QUOTES = ['‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '′' => "'", '“' => '"', '”' => '"', '„' => '"', '‟' => '"', '″' => '"'];

    private const DASHES = ['‐', '‑', '‒', '–', '—', '―', '−'];

    /**
     * @param  string  $text  The normalised text.
     * @param  list<int>  $map  For each character of $text, the offset of the character it came from.
     * @param  int  $length  The original's length, in characters.
     */
    private function __construct(
        public readonly string $text,
        private readonly array $map,
        public readonly int $length,
    ) {}

    public static function of(string $text, bool $markdown = false): self
    {
        $chars = mb_str_split($text);
        $skip = $markdown ? self::markdownSyntax($chars) : [];
        $out = [];
        $map = [];
        $space = false;

        foreach ($chars as $i => $char) {
            if (isset($skip[$i]) || self::invisible($char)) {
                continue;
            }

            if (self::isSpace($char)) {
                $space = $out !== [];

                continue;
            }

            if ($space) {
                $out[] = ' ';
                $map[] = $i - 1;
                $space = false;
            }

            foreach (self::fold($char) as $folded) {
                $out[] = $folded;
                $map[] = $i;
            }
        }

        return new self(implode('', $out), $map, count($chars));
    }

    /** The normalised form alone. */
    public static function string(string $text, bool $markdown = false): string
    {
        return self::of($text, $markdown)->text;
    }

    /**
     * The words of some text, lower-cased, with every character that is
     * not a letter or a digit taken as a space: what anchors and
     * similarity compare. Markdown and HTML syntax fall away with it.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = (string) preg_replace('/[\x{E0000}-\x{E007F}\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/<[^>]*>|\]\([^)]*\)/u', ' ', $text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * Where a span of the normalised text came from in the original:
     * [offset, length], in characters.
     *
     * @return array{0: int, 1: int}
     */
    public function original(int $offset, int $length): array
    {
        if ($this->map === [] || $length <= 0) {
            $at = $this->map[$offset] ?? $this->length;

            return [$at, 0];
        }

        $start = $this->map[$offset] ?? $this->length;
        $end = ($this->map[min($offset + $length, count($this->map)) - 1] ?? $this->length - 1) + 1;

        return [$start, max(0, $end - $start)];
    }

    /** The normalised text's length, in characters. */
    public function size(): int
    {
        return count($this->map);
    }

    /**
     * @return list<string>
     */
    private static function fold(string $char): array
    {
        if (isset(self::QUOTES[$char])) {
            return [self::QUOTES[$char]];
        }

        if (in_array($char, self::DASHES, true)) {
            return ['-'];
        }

        return $char === '…' ? ['.', '.', '.'] : [$char];
    }

    private static function isSpace(string $char): bool
    {
        return preg_match('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]$/u', $char) === 1;
    }

    private static function invisible(string $char): bool
    {
        return preg_match('/^[\x{E0000}-\x{E007F}\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]$/u', $char) === 1;
    }

    /**
     * The positions of markdown's inline syntax, to skip.
     *
     * @param  list<string>  $chars
     * @return array<int, true>
     */
    private static function markdownSyntax(array $chars): array
    {
        $skip = [];
        $count = count($chars);
        $lineStart = true;

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];

            if ($lineStart) {
                $i = self::skipLineStart($chars, $i, $skip);
                $lineStart = false;

                if ($i >= $count) {
                    break;
                }

                $char = $chars[$i];
            }

            if ($char === "\n") {
                $lineStart = true;

                continue;
            }

            if ($char === '\\' && isset($chars[$i + 1]) && preg_match('/^[\\\\`*_{}\[\]()#+\-.!>]$/', $chars[$i + 1]) === 1) {
                $skip[$i] = true;
                $i++;

                continue;
            }

            if ($char === '*' || $char === '_' || $char === '`' || $char === '[') {
                $skip[$i] = true;

                continue;
            }

            // A link's "](target)": the bracket, and the target up to its ")".
            if ($char === ']' && ($chars[$i + 1] ?? '') === '(') {
                $close = null;

                for ($j = $i + 2; $j < $count && $chars[$j] !== "\n"; $j++) {
                    if ($chars[$j] === ')') {
                        $close = $j;

                        break;
                    }
                }

                if ($close !== null) {
                    for ($j = $i; $j <= $close; $j++) {
                        $skip[$j] = true;
                    }

                    $i = $close;

                    continue;
                }
            }

            if ($char === ']') {
                $skip[$i] = true;
            }
        }

        return $skip;
    }

    /**
     * Skips a line's leading `#`s, `>`, bullet or number, and the spaces
     * after them; returns where the line's text starts.
     *
     * @param  list<string>  $chars
     * @param  array<int, true>  $skip
     */
    private static function skipLineStart(array $chars, int $i, array &$skip): int
    {
        $count = count($chars);

        while (true) {
            $j = $i;

            while ($j < $count && ($chars[$j] === ' ' || $chars[$j] === "\t")) {
                $j++;
            }

            $k = $j;

            if ($k < $count && $chars[$k] === '#') {
                while ($k < $count && $chars[$k] === '#') {
                    $k++;
                }
            } elseif ($k < $count && $chars[$k] === '>') {
                $k++;
            } elseif ($k < $count && in_array($chars[$k], ['-', '*', '+'], true) && ($chars[$k + 1] ?? '') === ' ') {
                $k++;
            } else {
                while ($k < $count && ctype_digit($chars[$k])) {
                    $k++;
                }

                if (! ($k > $j && $k < $count && ($chars[$k] === '.' || $chars[$k] === ')') && ($chars[$k + 1] ?? '') === ' ')) {
                    return $i;
                }

                $k++;
            }

            if ($k < $count && $chars[$k] !== ' ' && $chars[$k] !== "\t" && $chars[$k] !== "\n" && $chars[$k - 1] !== '>') {
                return $i;
            }

            for ($m = $i; $m < $k; $m++) {
                $skip[$m] = true;
            }

            while ($k < $count && ($chars[$k] === ' ' || $chars[$k] === "\t")) {
                $skip[$k] = true;
                $k++;
            }

            $i = $k;
        }
    }
}
