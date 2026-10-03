<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * The checks every scoped edit passes, whether a comment's revision (page
 * preview) or a suggested edit (Suggest edits): it stays in its scope,
 * keeps Ghostwriter's markers, adds no external link and no unsourced fact,
 * and stays a sensible size. No model is involved.
 *
 * `$before` and `$after` are the text the edit may change and what it
 * became: a quote and its replacement, or a whole unit before and after.
 * With a `$quote`, `$before` is the whole text and only the sentence or
 * sentences the quote is in may change.
 */
final class ScopedEditCheck
{
    /** It changed text outside its quote's sentences, or the quote isn't there. */
    public const SCOPE = 'scope';

    /** It lost an `[[ask: …]]` or a `#gw-link:` link, or added one it may not. */
    public const MARKERS = 'markers';

    /** It added a link to another site, an email address or a phone number. */
    public const LINK = 'link';

    /** It added a figure, quotation or name none of its sources has. */
    public const FACTS = 'facts';

    /** It's much shorter or longer than what it replaced. */
    public const SIZE = 'size';

    /** Size isn't checked for text this short, in words. */
    public const SIZE_MIN_WORDS = 3;

    private readonly SourceCheck $sources;

    private readonly QuoteFinder $quotes;

    public function __construct(?SourceCheck $sources = null, ?QuoteFinder $quotes = null)
    {
        $this->sources = $sources ?? new SourceCheck;
        $this->quotes = $quotes ?? new QuoteFinder;
    }

    /**
     * The problems with an edit, as the constants above, in that order;
     * empty when it passes.
     *
     * @param  array<int|string, string>  $sources  What facts may come from besides $before: the brief, the answers, a comment, a shown entry.
     * @param  float  $minRatio  The shortest $after may be, as a share of $before's words.
     * @param  float  $maxRatio  The longest.
     * @param  bool  $mayFillAsks  Whether an `[[ask: …]]` may be replaced by its answer (from a source).
     * @param  bool  $mayAddMarkers  Whether new `[[ask: …]]` markers and `#gw-link:` links may appear.
     * @return list<string>
     */
    public function check(
        string $before,
        string $after,
        array $sources = [],
        float $minRatio = 0.3,
        float $maxRatio = 1.5,
        ?TextQuote $quote = null,
        bool $mayFillAsks = false,
        bool $mayAddMarkers = false,
    ): array {
        $problems = [];
        [$was, $now] = [$before, $after];

        if ($quote !== null) {
            $scoped = $this->scope($before, $after, $quote);

            if ($scoped === null) {
                $problems[] = self::SCOPE;
            } else {
                [$was, $now] = $scoped;
            }
        }

        if (! $this->keepsMarkers($before, $after, $mayFillAsks, $mayAddMarkers)) {
            $problems[] = self::MARKERS;
        }

        if (array_diff(self::links($after), self::links($before."\n".implode("\n", $sources))) !== []) {
            $problems[] = self::LINK;
        }

        if ($this->sources->unsourced($now, [$before, ...array_values($sources)]) !== []) {
            $problems[] = self::FACTS;
        }

        $words = count(NormalisedText::words(Markers::withoutAsks($was)));

        if ($words >= self::SIZE_MIN_WORDS) {
            $ratio = count(NormalisedText::words(Markers::withoutAsks($now))) / $words;

            if ($ratio < $minRatio || $ratio > $maxRatio) {
                $problems[] = self::SIZE;
            }
        }

        return $problems;
    }

    /**
     * The quote's sentences before, and what took their place after; null
     * when the quote isn't in $before or the text around them changed.
     *
     * @return array{0: string, 1: string}|null
     */
    private function scope(string $before, string $after, TextQuote $quote): ?array
    {
        $match = $this->quotes->find($quote, $before, markdown: true);

        if ($match === null) {
            return null;
        }

        [$start, $length] = Sentences::covering($before, $match->offset, $match->length);
        $head = NormalisedText::string(mb_substr($before, 0, $start));
        $tail = NormalisedText::string(mb_substr($before, $start + $length));
        $now = NormalisedText::of($after);

        if (! str_starts_with($now->text, $head) || ($tail !== '' && ! str_ends_with($now->text, $tail)) || mb_strlen($head) + mb_strlen($tail) > $now->size()) {
            return null;
        }

        $middle = mb_substr($now->text, mb_strlen($head), $now->size() - mb_strlen($head) - mb_strlen($tail));

        return [mb_substr($before, $start, $length), $middle];
    }

    private function keepsMarkers(string $before, string $after, bool $mayFillAsks, bool $mayAddMarkers): bool
    {
        $asksBefore = self::counts(array_column(Markers::asks($before), 'hint'));
        $asksAfter = self::counts(array_column(Markers::asks($after), 'hint'));
        $linksBefore = self::counts(array_column(Markers::links($before), 'hint'));
        $linksAfter = self::counts(array_column(Markers::links($after), 'hint'));

        foreach ($linksBefore as $hint => $count) {
            if (($linksAfter[$hint] ?? 0) < $count) {
                return false;
            }
        }

        if (! $mayFillAsks) {
            foreach ($asksBefore as $hint => $count) {
                if (($asksAfter[$hint] ?? 0) < $count) {
                    return false;
                }
            }
        }

        if (! $mayAddMarkers) {
            foreach ([[$asksAfter, $asksBefore], [$linksAfter, $linksBefore]] as [$now, $was]) {
                foreach ($now as $hint => $count) {
                    if ($count > ($was[$hint] ?? 0)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $hints
     * @return array<string, int>
     */
    private static function counts(array $hints): array
    {
        $counts = [];

        foreach ($hints as $hint) {
            $key = Markers::normaliseHint($hint);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Links to other sites, email addresses and phone numbers, lower-cased.
     *
     * @return list<string>
     */
    private static function links(string $text): array
    {
        preg_match_all('/\b(?:https?:)?\/\/[^\s)<>"\]]+|\bwww\.[^\s)<>"\]]+|\bmailto:[^\s)<>"\]]+|\btel:[^\s)<>"\]]+/iu', $text, $matches);

        return array_values(array_unique(array_map(fn (string $link) => rtrim(mb_strtolower($link), '/.,;'), $matches[0])));
    }
}
