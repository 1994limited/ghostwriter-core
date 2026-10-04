<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;

/**
 * Checks the `seo-editor` call's link picks with no model (SEO layer
 * §7.2, §7.3), in the model's order, and drops any that breaks a rule:
 *
 * - **A real target:** one of the candidates shown (never the page itself,
 *   which isn't one), linkable (the dialect gives an href), and not linked
 *   twice. A pick with no target is a `#gw-link:` marker: one at most, with
 *   a hint.
 * - **The words are there, once:** exactly one match in that unit's text
 *   (repeats told apart by the prefix), never a fuzzy one.
 * - **A good anchor:** 2–8 words and at most 60 characters; not only stop
 *   words; nothing vague ("click here", "read more", "this page", in each
 *   language); not the page's own title; not a long target title pasted
 *   whole (an exact-match anchor of five words or more reads as stuffing).
 * - **A safe place:** in a unit that may take a link (prose, a section, a
 *   list, in a field whose editor has links); not in a heading, bold text,
 *   a quotation, an existing link, a marker, code or an address; within one
 *   sentence; not in the page's first sentence.
 * - **Spread and limits:** one link a unit (a unit is a paragraph run or a
 *   top-level section), and no more than the room left (decision 8:
 *   about one per 250 words, 2–5, links already on the page counted).
 */
final class LinkValidator
{
    public const MIN_WORDS = 2;

    public const MAX_WORDS = 8;

    public const MAX_LENGTH = 60;

    /** A target title this long, pasted whole as the words, reads as stuffing. */
    public const STUFFED_TITLE_WORDS = 5;

    /** Vague words in every language, whatever the page's. */
    private const VAGUE = ['click here', 'click', 'here', 'read more', 'more', 'this page', 'this link', 'link', 'learn more', 'find out more', 'more info', 'see more', 'go'];

    /** Places in markdown no link goes. */
    private const UNSAFE = [
        '/^[ \t]*#{1,6}[ \t].*$/mu',           // a heading
        '/^[ \t]*>.*$/mu',                      // a quotation
        '/!?\[[^\[\]\n]*\]\([^)\n]*\)/u',       // a link or an image
        '/\[\[[^\]\n]*\]\]/u',                  // a marker
        '/\*\*[^*\n]+?\*\*|__[^_\n]+?__/u',     // bold
        '/`[^`\n]*`/u',                         // code
        '/<[^>\n]+>/u',                         // markup
        '/\bhttps?:\/\/\S+/u',                  // an address
    ];

    /**
     * @param  list<LinkPick>  $picks  In the model's order.
     * @param  array<string, Unit>  $linkable  The units that may take a link, by id.
     * @param  string|null  $first  The id of the page's first unit of prose: its first sentence takes no link.
     */
    public function validate(array $picks, SeoRequest $request, array $linkable, InlineLinks $links, ?string $first = null, string $locale = 'en'): ValidatedLinks
    {
        $candidates = $request->byId();
        $kept = [];
        $dropped = [];
        $targets = [];
        $units = [];
        $markers = 0;
        $vague = self::vague($locale);
        $stop = array_flip(Phrases::for($locale)->stopWords ?? []);
        $title = self::normal($request->title);

        foreach ($picks as $pick) {
            $rule = null;
            $target = null;
            $href = null;
            $unit = $linkable[$pick->unit] ?? null;
            $words = NormalisedText::words($pick->exact);
            $normal = self::normal($pick->exact);

            if ($unit === null || in_array($unit->kind, [UnitKind::Quote, UnitKind::Text, UnitKind::Media, UnitKind::Row, UnitKind::Set], true)) {
                $rule = 'unit';
            } elseif (count($kept) >= $request->linkTarget) {
                $rule = 'limit';
            } elseif (isset($units[$pick->unit])) {
                $rule = 'spread';
            } elseif ($pick->isMarker()) {
                $hint = Markers::slug($pick->hint !== '' ? $pick->hint : $pick->exact);
                $rule = $markers >= 1 ? 'marker-limit' : ($pick->hint === '' ? 'marker-hint' : null);
                $href = Markers::link($hint);
            } elseif (($target = $candidates[$pick->target] ?? null) === null) {
                $rule = 'target';
            } elseif (isset($targets[$pick->target])) {
                $rule = 'duplicate';
            } elseif (($href = $links->inlineHref($target)) === null || trim($href) === '') {
                $rule = 'not-linkable';
            }

            if ($rule === null) {
                $rule = match (true) {
                    count($words) < self::MIN_WORDS || count($words) > self::MAX_WORDS || mb_strlen(trim($pick->exact)) > self::MAX_LENGTH => 'length',
                    array_diff($words, array_keys($stop)) === [] => 'stop-words',
                    self::isVague($normal, $vague) => 'vague',
                    $title !== '' && $normal === $title => 'own-title',
                    $target !== null && $normal === self::normal($target->title) && count(NormalisedText::words($target->title)) >= self::STUFFED_TITLE_WORDS => 'whole-title',
                    default => null,
                };
            }

            $match = null;

            if ($rule === null && $unit !== null) {
                [$match, $rule] = self::place($pick, $unit, $first);
            }

            if ($rule !== null || $match === null || $unit === null || $href === null) {
                $dropped[] = ['pick' => $pick, 'rule' => $rule ?? 'unit'];

                continue;
            }

            $units[$pick->unit] = true;

            if ($pick->isMarker()) {
                $markers++;
            } else {
                $targets[$pick->target] = true;
            }

            $kept[] = new PlacedLink($pick, $unit, $match[0], $match[1], $href, $target, 'l'.(count($kept) + 1));
        }

        return new ValidatedLinks($kept, $dropped);
    }

    /**
     * Where the words are in the unit, as [offset, length] in characters,
     * or the rule they break.
     *
     * @return array{0: array{0: int, 1: int}|null, 1: string|null}
     */
    private static function place(LinkPick $pick, Unit $unit, ?string $first): array
    {
        $text = $unit->markdown;
        $exact = rtrim(trim($pick->exact), ',;:');

        try {
            $quote = new TextQuote($exact, $pick->prefix);
        } catch (\InvalidArgumentException) {
            return [null, 'not-found'];
        }

        $found = (new QuoteFinder)->find($quote, $text);

        if ($found === null || $found->fuzzy) {
            $count = mb_substr_count(NormalisedText::string($text), NormalisedText::string($exact));

            return [null, $count > 1 ? 'ambiguous' : 'not-found'];
        }

        $offset = $found->offset;
        $length = $found->length;

        foreach (self::unsafe($text) as [$start, $end]) {
            if ($offset < $end && $offset + $length > $start) {
                return [null, 'unsafe-place'];
            }
        }

        if (! Sentences::inOneBlock($text, $offset, $length) || ! self::oneSentence($text, $offset, $length)) {
            return [null, 'crosses-sentence'];
        }

        if ($first !== null && $unit->id === $first && $offset < self::firstSentenceEnd($text)) {
            return [null, 'first-sentence'];
        }

        return [[$offset, $length], null];
    }

    /**
     * The ranges of some markdown no link may touch, in characters.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function unsafe(string $text): array
    {
        $ranges = [];

        foreach (self::UNSAFE as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$found, $byte]) {
                    $start = mb_strlen(substr($text, 0, $byte));
                    $ranges[] = [$start, $start + mb_strlen($found)];
                }
            }
        }

        return $ranges;
    }

    private static function oneSentence(string $text, int $offset, int $length): bool
    {
        $last = $offset + max(1, $length) - 1;

        foreach (Sentences::split($text) as [$at, $size]) {
            if ($offset >= $at && $offset < $at + $size) {
                return $last < $at + $size;
            }
        }

        return true;
    }

    /** Where the first sentence of a unit's prose ends: after any heading it opens with. */
    private static function firstSentenceEnd(string $text): int
    {
        foreach (Sentences::split($text) as [$at, $size]) {
            if (preg_match('/^[ \t]*#{1,6}[ \t]/u', mb_substr($text, $at, $size)) === 1) {
                continue;
            }

            return $at + $size;
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function vague(string $locale): array
    {
        $phrases = self::VAGUE;

        foreach (Phrases::LANGUAGES as $language) {
            array_push($phrases, ...(Phrases::for($language)->linkText ?? []));
        }

        if (($own = Phrases::for($locale)) !== null) {
            array_push($phrases, ...$own->linkText);
        }

        return array_values(array_unique(array_map(fn (string $phrase) => self::normal($phrase), $phrases)));
    }

    /**
     * Vague words: the anchor is one of the phrases, or holds one of more
     * than one word ("click here to book").
     *
     * @param  list<string>  $vague
     */
    private static function isVague(string $anchor, array $vague): bool
    {
        foreach ($vague as $phrase) {
            if ($phrase === '') {
                continue;
            }

            if ($anchor === $phrase || (str_contains($phrase, ' ') && preg_match('/(^| )'.preg_quote($phrase, '/').'( |$)/u', $anchor) === 1)) {
                return true;
            }
        }

        return false;
    }

    private static function normal(string $text): string
    {
        return implode(' ', NormalisedText::words($text));
    }
}
