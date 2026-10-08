<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\MarkdownSections;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Layout\InlineLinks;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
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
 * - **Spread and limits:** never two links in one paragraph, and at most
 *   two in a section (decision 27), links already there counted, and no
 *   more than the room left (decision 8: about one per 250 words, 2–5,
 *   links already on the page counted). A section is a unit: a top-level
 *   section of rich text, the prose before it, or a block's text field in
 *   a page builder. A paragraph is one of its pieces (MarkdownSections):
 *   a paragraph of Bard, CKEditor or TipTap, and each list item its own.
 *
 * judged() then applies the `seo-verifier`'s verdicts, checking any
 * better words it gives by the same rules.
 */
final class LinkValidator
{
    public const MIN_WORDS = 2;

    public const MAX_WORDS = 8;

    public const MAX_LENGTH = 60;

    /** A target title this long, pasted whole as the words, reads as stuffing. */
    public const STUFFED_TITLE_WORDS = 5;

    /** The most links a section (a unit) takes, its existing ones counted. A paragraph takes one. */
    public const PER_SECTION = 2;

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
        $taken = [];
        $markers = 0;
        $vague = self::vague($locale);
        $stop = self::stopWords($locale);
        $title = self::normal($request->title);

        foreach ($picks as $pick) {
            $rule = null;
            $target = null;
            $href = null;
            $unit = $linkable[$pick->unit] ?? null;

            if ($unit === null || in_array($unit->kind, [UnitKind::Quote, UnitKind::Text, UnitKind::Media, UnitKind::Row, UnitKind::Set], true)) {
                $rule = 'unit';
            } elseif (count($kept) >= $request->linkTarget) {
                $rule = 'limit';
            } elseif (($taken[$pick->unit] ??= self::taken($unit))['links'] >= self::PER_SECTION) {
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
                $rule = self::anchorRule($pick->exact, $target, $title, $vague, $stop);
            }

            $match = null;

            if ($rule === null && $unit !== null) {
                [$match, $rule] = self::place($pick, $unit, $first);
            }

            $paragraph = $match === null ? null : self::paragraphAt($taken[$pick->unit]['paragraphs'] ?? [], $match[0]);

            if ($rule === null && $paragraph !== null && ($taken[$pick->unit]['counts'][$paragraph] ?? 0) > 0) {
                $rule = 'paragraph';
            }

            if ($rule !== null || $match === null || $unit === null || $href === null) {
                $dropped[] = ['pick' => $pick, 'rule' => $rule ?? 'unit'];

                continue;
            }

            $taken[$pick->unit]['links']++;

            if ($paragraph !== null) {
                $taken[$pick->unit]['counts'][$paragraph]++;
            }

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
     * The verifier's verdicts applied (SEO layer §8.3): a dropped link
     * goes; a link it kept with better words (`keep-with-anchor`) is moved
     * onto them once they pass every check its first words passed (there,
     * once in the unit, a good anchor, a safe place, not the first
     * sentence) and lie in the same sentence. When they don't, the link
     * keeps its first words: they passed those checks, and the verifier
     * judged the page right. The sentence, so the paragraph, the unit and
     * the page don't change, so the spread and the limits still hold. Ids
     * are kept.
     */
    public function judged(ValidatedLinks $validated, LinkVerdicts $verdicts, SeoRequest $request, ?string $first = null, string $locale = 'en'): ValidatedLinks
    {
        $kept = [];
        $dropped = $validated->dropped;
        $anchored = $validated->anchored;

        foreach ($validated->kept as $link) {
            if ($verdicts->drops($link->id)) {
                $dropped[] = ['pick' => $link->pick, 'rule' => 'verifier: '.$verdicts->drop[$link->id]];

                continue;
            }

            $better = $verdicts->anchors[$link->id] ?? null;

            if ($better === null || $link->target === null || self::normal($better['anchor']) === self::normal($link->words())) {
                $kept[] = $link;

                continue;
            }

            [$moved, $rule] = $this->reanchor($link, $better['anchor'], $request, $first, $locale);
            $anchored[] = ['from' => $link->words(), 'to' => $better['anchor'], 'rule' => $rule, 'why' => $better['why']];
            $kept[] = $moved ?? $link;
        }

        return new ValidatedLinks($kept, $dropped, $anchored);
    }

    /**
     * The link moved onto other words of its unit, or the rule they break.
     *
     * @return array{0: PlacedLink|null, 1: string|null}
     */
    private function reanchor(PlacedLink $link, string $words, SeoRequest $request, ?string $first, string $locale): array
    {
        $pick = new LinkPick($link->pick->unit, $words, $link->pick->target, '', $link->pick->hint, $link->pick->why);
        $rule = self::anchorRule($words, $link->target, self::normal($request->title), self::vague($locale), self::stopWords($locale));

        if ($rule !== null) {
            return [null, $rule];
        }

        [$match, $rule] = self::place($pick, $link->unit, $first);

        if ($match === null) {
            return [null, $rule ?? 'not-found'];
        }

        $sentence = self::sentenceAt($link->unit->markdown, $link->offset);

        if ($sentence !== null && ($match[0] < $sentence[0] || $sentence[1] < $match[0] + $match[1])) {
            return [null, 'other-sentence'];
        }

        return [new PlacedLink($pick, $link->unit, $match[0], $match[1], $link->href, $link->target, $link->id), null];
    }

    /**
     * What's wrong with these words as a link's, if anything: their
     * length, only stop words, vague, the page's own title, or a long
     * target title pasted whole.
     *
     * @param  list<string>  $vague
     * @param  array<string, int>  $stop
     */
    private static function anchorRule(string $exact, ?DigestEntry $target, string $title, array $vague, array $stop): ?string
    {
        $words = NormalisedText::words($exact);
        $normal = self::normal($exact);

        return match (true) {
            count($words) < self::MIN_WORDS || count($words) > self::MAX_WORDS || mb_strlen(trim($exact)) > self::MAX_LENGTH => 'length',
            array_diff($words, array_keys($stop)) === [] => 'stop-words',
            self::isVague($normal, $vague) => 'vague',
            $title !== '' && $normal === $title => 'own-title',
            $target !== null && $normal === self::normal($target->title) && count(NormalisedText::words($target->title)) >= self::STUFFED_TITLE_WORDS => 'whole-title',
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private static function stopWords(string $locale): array
    {
        return array_flip(Phrases::for($locale)->stopWords ?? []);
    }

    /**
     * The sentence holding this offset, as [start, end] in characters.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function sentenceAt(string $text, int $offset): ?array
    {
        foreach (Sentences::split($text) as [$at, $size]) {
            if ($offset >= $at && $offset < $at + $size) {
                return [$at, $at + $size];
            }
        }

        return null;
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
     * A unit's paragraphs (its pieces: a paragraph, a list item, a heading…)
     * as [start, end] in characters, the links in each, and in all: those
     * it has already, a writer's `#gw-link:` among them, are counted. A
     * list value's items are lines with no bullet: each is a paragraph.
     *
     * @return array{paragraphs: list<array{0: int, 1: int}>, counts: list<int>, links: int}
     */
    private static function taken(Unit $unit): array
    {
        $text = $unit->markdown;
        $split = preg_split('/\r\n|\r|\n/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $lines = array_map(fn (array $line) => $line[0], $split);
        $starts = array_map(fn (array $line) => mb_strlen(substr($text, 0, $line[1])), $split);
        $at = mb_strlen($text);
        $blocks = $unit->kind === UnitKind::List && preg_grep('/^\s*(?:[-*+]|\d+[.)])\s+/', $lines) === []
            ? array_map(fn (int $i) => ['start' => $i, 'end' => $i + 1], array_keys(array_filter($lines, fn (string $line) => trim($line) !== '')))
            : MarkdownSections::blocks($lines);

        $paragraphs = [];
        $counts = [];

        foreach ($blocks as $block) {
            $start = $starts[$block['start']];
            $end = $block['end'] < count($starts) ? $starts[$block['end']] : $at;
            $count = preg_match_all('/(?<!!)\[[^\[\]\n]*\]\([^)\n]*\)/u', mb_substr($text, $start, $end - $start));
            $paragraphs[] = [$start, $end];
            $counts[] = (int) $count;
        }

        return ['paragraphs' => $paragraphs, 'counts' => $counts, 'links' => array_sum($counts)];
    }

    /**
     * Which of the paragraphs holds this offset.
     *
     * @param  list<array{0: int, 1: int}>  $paragraphs
     */
    private static function paragraphAt(array $paragraphs, int $offset): ?int
    {
        foreach ($paragraphs as $i => [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return $i;
            }
        }

        return null;
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
