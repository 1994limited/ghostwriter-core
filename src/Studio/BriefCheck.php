<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * Keeps facts the person didn't give out of a filled brief. The brief
 * filler is told never to invent facts about the organisation; this
 * checks what came back, as fillGap() does, without asking again:
 *
 * - a figure (a number, price, percentage, date or year) that the person
 *   didn't give, and that the kind's own text doesn't have, becomes
 *   `[Add: the figure]`. Figures are compared by what they say (Figures):
 *   "800", "eight hundred" and "800-word" are the same, as are "£12k" and
 *   "£12,000". A length or count of the piece itself ("about 600 words",
 *   "a 10-minute read", "three sections") is shape, not a fact, and stays;
 * - a quotation the person didn't give becomes `[Add: the quote]`, when it
 *   is speech or a testimonial ("they said …", "Testimonial: …") or can't
 *   be told apart from one. Quoted titles and names stay: the titles of
 *   entries the model was given, the working title, terms the person used,
 *   and titles or headings the brief proposes ("sections: …", "called …");
 * - a figure inside one of those titles is part of the title, not a fact;
 * - an answer to a question with set answers that isn't one of them is
 *   left empty;
 * - a required question left empty becomes `[Add: <the question>]`, so the
 *   person sees it is theirs to answer (one with set answers stays empty).
 *
 * Text already in square brackets is the person's to fill and is left
 * alone.
 */
final class BriefCheck
{
    public const FIGURE = '[Add: the figure]';

    public const QUOTE = '[Add: the quote]';

    /** What a figure counts when it is the piece's own shape. */
    private const SHAPE_UNITS = 'words?|sentences?|paragraphs?|sections?|headings?|steps?|points?|tips?|items?|minutes?(?:\s+(?:read|reading))?|characters?|lines?|bullets?|bullet\s+points?|examples?|questions?|parts?|images?|photos?|pictures?|links?|blocks?|chapters?|pages?|slides?|reasons?|ways?|things?|ideas?|mistakes?|lessons?';

    /** A quotation in straight or curly double quotes, long enough to be one. */
    private const QUOTE_PATTERN = '/["“]([^"“”\n]{12,})["”]/u';

    /** Any text in double quotes, however short ("Mill 2"). */
    private const QUOTED = '/["“]([^"“”\n]+)["”]/u';

    /** Before a quotation: someone said it. */
    private const SPEECH_BEFORE = '/(?:\b(?:said|says|saying|told|tells|wrote|writes|put\s+it|in\s+(?:his|her|their|our|my|your)(?:\s+own)?\s+words|according\s+to|quote[sd]?|quotation|testimonials?|reviews?|feedback|praised|described\s+(?:it|us|them|the\s+\w+)\s+as|called\s+(?:it|us|them|the\s+\w+)|recall(?:s|ed)|explain(?:s|ed)|comment(?:s|ed)|remark(?:s|ed)|add(?:s|ed)|insist(?:s|ed)|state[sd]|admit(?:s|ted)|thanks?|loved?|raved?)\b[^.!?]{0,40}$)/iu';

    /** After a quotation: who said it ("…," she said; "…" — Jane, CEO). */
    private const SPEECH_AFTER = '/\A\s*(?:,?\s*(?:\p{L}+\s+){0,3}?(?:said|says|told|wrote|writes|adds|added|explains|explained|recalls|recalled)\b|[—–-]\s*\p{Lu})/u';

    /** Before a quotation: it names something (a title, a heading, a section). */
    private const NAMING_BEFORE = '/\b(?:titled|entitled|called|named|headed|headings?|headlines?|titles?|subtitles?|working\s+title|sections?|parts?|chapters?|taglines?|subject\s+lines?|h[1-4]s?|e\.g\.|for\s+example|such\s+as|something\s+like|angle|hook|term|phrase|label)\b[^.!?]*$/iu';

    /** Text in single square brackets, not a writer's `[[ask: …]]`. */
    private const BRACKETS = '/(?<!\[)\[(?!\[)[^\[\]\n]+\](?!\])/u';

    /**
     * The answers with anything the person didn't say taken out, and what
     * was taken out, for the log (`handle: what`).
     *
     * @param  array<string, string>  $answers  By handle.
     * @param  string  $source  Everything the person said (BriefRequest::source()).
     * @param  array<int, string>  $kept  Questions whose answers the person wrote, left as they are.
     * @param  array<int, string|null>  $titles  Titles the model was given or gave: the group's entries (which the examples to model it on come from) and the working title.
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function check(ContentKind $kind, array $answers, string $source, array $kept = [], array $titles = []): array
    {
        $given = $source."\n".self::kindText($kind);
        $known = Figures::known($given);
        $said = ' '.self::loose($given).' ';
        $titles = array_values(array_filter(array_map(fn (?string $title) => trim((string) $title), $titles), fn (string $title) => self::loose($title) !== ''));
        $problems = [];
        $out = [];

        foreach ($kind->questions as $question) {
            $answer = trim($answers[$question->handle] ?? '');

            if (in_array($question->handle, $kept, true)) {
                $out[$question->handle] = $answer;

                continue;
            }

            if ($question->options !== []) {
                $answer = self::option($question, $answer);
            } else {
                $answer = self::outsideBrackets($answer, function (string $text) use ($known, $said, $titles, $question, &$problems): string {
                    $text = (string) preg_replace_callback(self::QUOTE_PATTERN, function (array $match) use ($text, $said, $titles, $question, &$problems): string {
                        [$quote, $offset] = $match[0];

                        if (self::isGiven($match[1][0], $said, $titles) || self::isName($text, $offset, $offset + strlen($quote))) {
                            return $quote;
                        }

                        $problems[] = "{$question->handle}: a quotation";

                        return self::QUOTE;
                    }, $text, flags: PREG_OFFSET_CAPTURE);

                    $titled = self::titled($text, $said, $titles);
                    $figures = [];

                    // From the end, so the offsets found stay right.
                    foreach (array_reverse(Figures::find($text)) as $found) {
                        ['figure' => $figure, 'offset' => $offset] = $found;

                        if (Figures::given($found['values'], $known) || self::isShape($text, $offset + strlen($figure)) || self::within($offset, $titled)) {
                            continue;
                        }

                        array_unshift($figures, "{$question->handle}: a figure ({$figure})");
                        $text = substr_replace($text, self::FIGURE, $offset, strlen($figure));
                    }

                    array_push($problems, ...$figures);

                    return $text;
                });
            }

            if ($answer === '' && $question->required && $question->options === []) {
                $answer = '[Add: '.$question->label.']';
            }

            $out[$question->handle] = $answer;
        }

        return [$out, $problems];
    }

    /**
     * Whether some text has a part in square brackets for the person.
     */
    public static function hasBrackets(string $text): bool
    {
        return preg_match(self::BRACKETS, $text) === 1;
    }

    /**
     * Whether quoted text is something the person or the given context
     * supplied: words the person used, or a title the model was given.
     *
     * @param  list<string>  $titles
     */
    private static function isGiven(string $quoted, string $said, array $titles): bool
    {
        $quoted = self::loose($quoted);

        if ($quoted === '' || str_contains($said, " {$quoted} ")) {
            return true;
        }

        foreach ($titles as $title) {
            $title = self::loose($title);

            if ($title === $quoted || (mb_strlen($quoted) >= 3 && str_contains(" {$title} ", " {$quoted} "))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a quotation (from `$start` to `$end` in the text) names
     * something, such as a proposed title or heading, rather than reports
     * what someone said. Speech wins when there are signs of both.
     */
    private static function isName(string $text, int $start, int $end): bool
    {
        // Earlier quotations on the line don't count as words before this one.
        $line = substr($text, 0, $start);
        $line = substr($line, (int) strrpos("\n".$line, "\n"));
        $before = (string) preg_replace(self::QUOTED, '…', $line);
        $after = substr($text, $end);

        if (preg_match(self::SPEECH_BEFORE, $before) === 1 || preg_match(self::SPEECH_AFTER, $after) === 1) {
            return false;
        }

        return preg_match(self::NAMING_BEFORE, $before) === 1;
    }

    /**
     * Where the text quotes something given, or names a given title
     * without quotes: [start, end] in bytes. A figure there is part of a
     * title.
     *
     * @param  list<string>  $titles
     * @return list<array{0: int, 1: int}>
     */
    private static function titled(string $text, string $said, array $titles): array
    {
        $spans = [];

        if (preg_match_all(self::QUOTED, $text, $quoted, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($quoted as $match) {
                if (self::isGiven($match[1][0], $said, $titles) || (mb_strlen($match[1][0]) >= 12 && self::isName($text, $match[0][1], $match[0][1] + strlen($match[0][0])))) {
                    $spans[] = [$match[0][1], $match[0][1] + strlen($match[0][0])];
                }
            }
        }

        foreach ($titles as $title) {
            if (preg_match('/\d/', $title) === 1 && preg_match_all('/(?<!\w)'.preg_quote($title, '/').'(?!\w)/iu', $text, $named, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($named[0] as [$name, $offset]) {
                    $spans[] = [$offset, $offset + strlen($name)];
                }
            }
        }

        return $spans;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     */
    private static function within(int $offset, array $spans): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the figure ending at this offset counts the piece itself:
     * "600 words", "3 short sections", "800-word", "1,500–2,000 words".
     */
    private static function isShape(string $text, int $end): bool
    {
        return preg_match('/\G(?:\s*|-)(?:\p{L}+[\s-]+){0,2}?(?:'.self::SHAPE_UNITS.')\b/iu', $text, $match, 0, $end) === 1;
    }

    /**
     * An answer to a question with set answers: one of the values (or a
     * label, read as its value), or nothing.
     */
    private static function option(Question $question, string $answer): string
    {
        if ($answer === '' || array_key_exists($answer, $question->options)) {
            return $answer;
        }

        foreach ($question->options as $value => $label) {
            if (mb_strtolower(trim($label)) === mb_strtolower($answer) || mb_strtolower((string) $value) === mb_strtolower($answer)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * The text with each part outside square brackets changed, and the
     * bracketed parts as they were.
     *
     * @param  callable(string): string  $change
     */
    private static function outsideBrackets(string $text, callable $change): string
    {
        $parts = preg_split(self::BRACKETS, $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$text, 0]];
        $out = '';
        $at = 0;

        foreach ($parts as [$part, $offset]) {
            $out .= substr($text, $at, $offset - $at).$change($part);
            $at = $offset + strlen($part);
        }

        return $out.substr($text, $at);
    }

    private static function kindText(ContentKind $kind): string
    {
        $text = [$kind->title, $kind->description, $kind->guidance, ...$kind->checklist];

        foreach ($kind->questions as $question) {
            $text[] = $question->label;
            $text[] = $question->instructions;
            array_push($text, ...array_keys($question->options), ...array_values($question->options));
        }

        return implode("\n", array_map('strval', $text));
    }

    /**
     * Text as compared for quotations and titles: lower case, letters and
     * digits only, single spaces.
     */
    private static function loose(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(str_replace(["'", '’', '‘'], '', $text))));
    }
}
