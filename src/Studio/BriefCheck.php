<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * Keeps facts the person didn't give out of a filled brief. The brief
 * filler is told never to invent facts about the organisation; this
 * checks what came back, as fillGap() does, without asking again:
 *
 * - a figure (a number, price, percentage or year) that the person didn't
 *   give, and that the kind's own text doesn't have, becomes
 *   `[Add: the figure]`; a length or count of the piece itself ("about
 *   600 words", "three sections") is shape, not a fact, and stays;
 * - a quotation the person didn't give becomes `[Add: the quote]`;
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

    /** A number, with a currency before it or a unit of amount after it. */
    private const FIGURE_PATTERN = '/(?:[£$€¥]\s?)?(?<!\w)\d+(?:[.,]\d+)*(?:\s?(?:%|per\s?cent|percent|k|m|bn|million|billion|thousand)\b|%)?/iu';

    /** What a figure counts when it is the piece's own shape. */
    private const SHAPE_UNITS = 'words?|sentences?|paragraphs?|sections?|headings?|steps?|points?|tips?|items?|minutes?(?:\s+(?:read|reading))?|characters?|lines?|bullets?|bullet\s+points?|examples?|questions?|parts?|images?|photos?|pictures?|links?|blocks?|chapters?|pages?|slides?|reasons?|ways?|things?|ideas?|mistakes?|lessons?';

    /** A quotation in straight or curly double quotes, long enough to be one. */
    private const QUOTE_PATTERN = '/["“]([^"“”\n]{12,})["”]/u';

    /** Text in single square brackets, not a writer's `[[ask: …]]`. */
    private const BRACKETS = '/(?<!\[)\[(?!\[)[^\[\]\n]+\](?!\])/u';

    /**
     * The answers with anything the person didn't say taken out, and what
     * was taken out, for the log (`handle: what`).
     *
     * @param  array<string, string>  $answers  By handle.
     * @param  string  $source  Everything the person said (BriefRequest::source()).
     * @param  array<int, string>  $kept  Questions whose answers the person wrote, left as they are.
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function check(ContentKind $kind, array $answers, string $source, array $kept = []): array
    {
        $known = self::figures($source.' '.self::kindText($kind));
        $said = self::normalise($source.' '.self::kindText($kind));
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
                $answer = self::outsideBrackets($answer, function (string $text) use ($known, $said, $question, &$problems): string {
                    $text = (string) preg_replace_callback(self::QUOTE_PATTERN, function (array $match) use ($said, $question, &$problems): string {
                        if (str_contains($said, self::normalise($match[1]))) {
                            return $match[0];
                        }

                        $problems[] = "{$question->handle}: a quotation";

                        return self::QUOTE;
                    }, $text);

                    // From the end, so the offsets found stay right.
                    $found = preg_match_all(self::FIGURE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0 ? $matches : [];
                    $figures = [];

                    foreach (array_reverse($found) as $match) {
                        [$figure, $offset] = $match[0];

                        if (in_array(self::figureKey($figure), $known, true) || self::isShape($text, $offset + strlen($figure))) {
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
     * The figures in some text, as compared: digits and decimal point only.
     *
     * @return list<string>
     */
    private static function figures(string $text): array
    {
        return preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $found) > 0
            ? array_values(array_unique(array_map(self::figureKey(...), $found[0])))
            : [];
    }

    private static function figureKey(string $figure): string
    {
        return preg_match('/\d+(?:[.,]\d+)*/u', $figure, $match) === 1 ? str_replace(',', '', $match[0]) : $figure;
    }

    /**
     * Whether the figure ending at this offset counts the piece itself:
     * "600 words", "3 short sections".
     */
    private static function isShape(string $text, int $end): bool
    {
        return preg_match('/\G\s*(?:[-–]\s*\d+\s*)?(?:\p{L}+\s+){0,2}?(?:'.self::SHAPE_UNITS.')\b/iu', $text, $match, 0, $end) === 1;
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

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(['’', '‘'], "'", $text))));
    }
}
