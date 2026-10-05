<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\SourceCheck;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * The checks on a search title or description the `seo-editor` call wrote
 * (SEO layer §9.2, §5.3). No model.
 *
 * - **Length:** inside MetaRange.
 * - **Nothing the page doesn't say:** no figure, quotation or name that the
 *   draft, the brief and the editor's answers don't have (Anchor\
 *   SourceCheck).
 * - **No unconfirmed facts:** no marker, and no figure that the sources
 *   have only inside an unresolved `[[check: …]]` or `[[ask: …]]`: a count
 *   still to confirm doesn't leak into the search snippet.
 * - **Plain:** one line, no emoji, no `!`, no word over four letters in
 *   capitals.
 * - **A title of its own:** a title isn't the page title word for word.
 *
 * settle() says what may be used: the text when it passes; cut at a word
 * (Text\Slug::clip()) when it is only too long; nothing otherwise, with
 * the problems for the log and for the one retry.
 */
final class SeoMetaCheck
{
    public const EMPTY = 'empty';

    public const TOO_LONG = 'too-long';

    public const TOO_SHORT = 'too-short';

    public const MARKER = 'marker';

    public const UNCONFIRMED = 'unconfirmed';

    public const UNSOURCED = 'unsourced';

    public const FORMAT = 'format';

    public const SAME_AS_TITLE = 'same-as-title';

    public function __construct(private readonly SourceCheck $sources = new SourceCheck) {}

    /**
     * What is wrong with the text, by code, each with a sentence the model
     * can act on. Empty when nothing is.
     *
     * @param  array<int|string, string>  $sources  What the page may say: the draft's text, the brief and the answers, markers and all.
     * @return array<string, string>
     */
    public function problems(string $text, MetaRange $range, array $sources, string $pageTitle = ''): array
    {
        $what = $range->role === SeoField::TITLE ? 'search title' : 'search description';
        $text = trim($text);

        if ($text === '') {
            return [self::EMPTY => "The {$what} is empty."];
        }

        $problems = [];
        $length = mb_strlen($text);

        if ($length > $range->max) {
            $problems[self::TOO_LONG] = "The {$what} is {$length} characters; keep it to {$range->min}–{$range->max}.";
        } elseif ($length < $range->min) {
            $problems[self::TOO_SHORT] = "The {$what} is {$length} characters; make it {$range->min}–{$range->max}.";
        }

        if (str_contains($text, '[[') || Markers::has($text)) {
            $problems[self::MARKER] = "The {$what} has a marker in it; write only what the page already says.";
        }

        $clean = array_map(fn (string $source) => self::confirmed($source), array_values($sources));
        $marked = implode("\n", array_map(fn (string $source) => self::unconfirmed($source), array_values($sources)));
        $unconfirmed = [];
        $unsourced = [];

        $evenWithMarkers = $marked === '' ? null : $this->sources->unsourced(self::confirmed($text), [...$clean, $marked]);

        foreach ($this->sources->unsourced(self::confirmed($text), $clean) as $fact) {
            if ($evenWithMarkers !== null && ! in_array($fact, $evenWithMarkers, true)) {
                $unconfirmed[] = $fact;
            } else {
                $unsourced[] = $fact;
            }
        }

        if ($unconfirmed !== []) {
            $problems[self::UNCONFIRMED] = "The {$what} uses ".self::quoted($unconfirmed).', which the page hasn\'t confirmed yet ("to be confirmed"); leave '.(count($unconfirmed) === 1 ? 'it' : 'them').' out.';
        }

        if ($unsourced !== []) {
            $problems[self::UNSOURCED] = "The {$what} says ".self::quoted($unsourced).', which the page doesn\'t; use only what the page says.';
        }

        if (preg_match('/[\r\n]/u', $text) === 1 || str_contains($text, '!') || preg_match('/\p{Extended_Pictographic}/u', $text) === 1 || preg_match('/\b\p{Lu}{5,}\b/u', $text) === 1) {
            $problems[self::FORMAT] = "The {$what} must be one plain line: no line breaks, emoji, exclamation marks or words in capitals.";
        }

        if ($range->role === SeoField::TITLE && $pageTitle !== '' && self::same($text, $pageTitle)) {
            $problems[self::SAME_AS_TITLE] = 'The search title is the page title word for word; give it its own, or none.';
        }

        return $problems;
    }

    /**
     * The text to use: as written when it passes; cut at a word when it is
     * only too long and still long enough after the cut; '' otherwise.
     *
     * @param  array<int|string, string>  $sources
     * @return array{0: string, 1: array<string, string>} The text, and what was wrong with what was written.
     */
    public function settle(string $text, MetaRange $range, array $sources, string $pageTitle = ''): array
    {
        $text = Slug::clip(trim($text), 2000);
        $problems = $this->problems($text, $range, $sources, $pageTitle);

        if ($problems === []) {
            return [$text, []];
        }

        if (array_keys($problems) === [self::TOO_LONG]) {
            $cut = rtrim(Slug::clip($text, $range->max), ' .,;:–—-');

            if ($range->fits($cut) && $this->problems($cut, $range, $sources, $pageTitle) === []) {
                return [$cut, $problems];
            }
        }

        return ['', $problems];
    }

    /** Text with every unresolved marker taken out: what the page says for sure. */
    public static function confirmed(string $text): string
    {
        foreach (Markers::checks($text) as $check) {
            $text = str_replace($check['match'], ' ', $text);
        }

        return (string) preg_replace('/\[\[[^\[\]]*\]\]/u', ' ', Markers::withoutAsks($text));
    }

    /** Only what is inside the unresolved markers of some text. */
    private static function unconfirmed(string $text): string
    {
        $inside = [];

        foreach (Markers::checks($text) as $check) {
            $inside[] = $check['value'].' '.$check['list'];
        }

        foreach (Markers::asks($text) as $ask) {
            $inside[] = is_array($ask) ? implode(' ', array_filter($ask, 'is_string')) : (string) $ask;
        }

        return implode("\n", $inside);
    }

    private static function same(string $a, string $b): bool
    {
        return NormalisedText::words(mb_strtolower($a)) === NormalisedText::words(mb_strtolower($b));
    }

    /**
     * @param  list<string>  $facts
     */
    private static function quoted(array $facts): string
    {
        return implode(', ', array_map(fn (string $fact) => "“{$fact}”", array_slice($facts, 0, 4)));
    }
}
