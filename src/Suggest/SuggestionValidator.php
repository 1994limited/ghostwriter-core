<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\ScopedEditCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\SentenceFit;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\SourceCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;

/**
 * Decides which of the model's suggestions are kept, with no model. A
 * suggestion that fails a check is dropped, never repaired, and counted
 * by reason (DROPS):
 *
 * - `anchor`: its unit isn't in the call, or its quote isn't found there
 *   (QuoteFinder: exact, then by context, then one fuzzy match ≥ 0.9,
 *   which is re-quoted to the real text), or it crosses a paragraph. A
 *   whole-value suggestion only for a short field (≤ SHORT_FIELD
 *   characters) or an SEO field.
 * - `finding`: a candidate answered twice, or one that doesn't exist. A
 *   suggestion naming a candidate takes the finding's anchor and
 *   category, whatever it says.
 * - `declined`: a candidate the model dropped in context (any category
 *   may be). It isn't shown; it goes in ValidatedReview::$checked with
 *   the model's reason.
 * - `unanswered`: a candidate the model neither kept nor dropped. It
 *   isn't shown, and isn't checked: it is a candidate again next time.
 * - `scope`, `size`, `markers`, `link`, `facts`: ScopedEditCheck on the
 *   replacement and each alternative (an alternative that fails is
 *   dropped alone).
 * - `fit`: Anchor\SentenceFit on the replacement and each alternative,
 *   after the checks above: the field still reads as whole sentences (a
 *   capital where a sentence starts, the same end punctuation, no word
 *   doubled at a join, nothing empty mid-sentence). Every figure, quotation and name must be on the page
 *   or in the cited site entry (SourceCheck). Clarity may shrink to 20%,
 *   Duplicate to nothing; an SEO value must fit its limit.
 * - `facts` too: a Fact to check whose template isn't the quote with
 *   exactly one span replaced by `{answer}`, or whose version without
 *   adds anything. A Fact to check never keeps a replacement.
 * - `claims`: a claim the model flagged on its own, with the site's claim
 *   checks off.
 * - `voice`: a Voice suggestion with no voice guide written.
 * - `dismissed`: what a decision keeps quiet (Quieted).
 * - `overlap`: two in one place: a finding's fix beats the model's own,
 *   then the lower Category::rank(), then the shorter range.
 * - `cap`: over the cap (per call, and PER_UNIT in a unit), by rank then
 *   page order; `page-share`: the model's own suggestions would change
 *   more than PAGE_SHARE of the page's words.
 * - `verifier`: dropped by the second pass (verify()).
 * - `inherited`: a new SEO value where the page inherits one that fits
 *   (another field's text, a section's default: SeoSource), which stays
 *   as the site set it up.
 *
 * A source the model claims that can't be shown (a voice guide heading
 * that isn't one, an entry it wasn't shown) becomes `general`, and the
 * reason is kept. Nothing reaches the editor that the model didn't judge:
 * there is no free fallback for a candidate it left. A kept candidate
 * with nothing to write is kept with no replacement only where the words
 * can't be the fix: a link field, a broken link (the link is the fix) and
 * an image whose picture wasn't attached ("Describe the image yourself").
 */
final class SuggestionValidator
{
    public const DROPS = ['unreadable', 'anchor', 'finding', 'declined', 'unanswered', 'scope', 'size', 'markers', 'link', 'facts', 'fit', 'claims', 'voice', 'dismissed', 'overlap', 'cap', 'page-share', 'verifier', 'inherited'];

    public const SHORT_FIELD = 120;

    public const PER_UNIT = 3;

    public const PAGE_SHARE = 0.3;

    public const ALT_LIMIT = 125;

    private readonly QuoteFinder $quotes;

    private readonly ScopedEditCheck $scoped;

    private readonly SourceCheck $sources;

    public function __construct(?QuoteFinder $quotes = null, ?SourceCheck $sources = null)
    {
        $this->quotes = $quotes ?? new QuoteFinder;
        $this->sources = $sources ?? new SourceCheck;
        $this->scoped = new ScopedEditCheck($this->sources, $this->quotes);
    }

    public function validate(SuggestionReply $reply, ReviewInput $input): ValidatedReview
    {
        $dropped = [];
        $drop = function (string $why) use (&$dropped): void {
            $dropped[$why] = ($dropped[$why] ?? 0) + 1;
        };

        $numbered = $input->numbered();
        $batches = $input->batches();
        $answered = [];
        $checked = [];
        /** @var list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}> $kept */
        $kept = [];

        foreach ($reply->items as $order => ['batch' => $batchIndex, 'item' => $item]) {
            $batch = $batches[$batchIndex] ?? null;
            $number = is_string($item['finding'] ?? null) ? $item['finding'] : null;
            $finding = $number !== null ? ($numbered[$number] ?? null) : null;
            $item['drop'] ??= $item['decline'] ?? null;

            if ($batch === null || ($number !== null && $finding === null) || ($finding === null && isset($item['drop']))) {
                $drop($batch === null ? 'unreadable' : 'finding');

                continue;
            }

            if ($finding !== null && isset($answered[$finding->id])) {
                $drop('finding');

                continue;
            }

            if ($finding !== null) {
                $answered[$finding->id] = true;
            }

            if ($finding !== null && isset($item['drop'])) {
                $checked[] = ['id' => $finding->id, 'passage' => $finding->anchor->passage, 'reason' => is_string($item['drop']) ? trim(mb_substr($item['drop'], 0, 200)) : '', 'by' => 'reviewer'];
                $drop('declined');

                continue;
            }

            $result = $this->one($item, $finding, $batch, $input, $reply);

            if (is_string($result)) {
                $drop($result);

                continue;
            }

            if ($input->context->quieted->covers($result['suggestion']->id, $result['suggestion']->anchor->passage, $input->context->now)) {
                $drop('dismissed');

                continue;
            }

            $kept[] = $result + ['own' => $finding === null, 'order' => $order];
        }

        // Candidates the model left: not shown, and a candidate again next time.
        foreach ($numbered as $finding) {
            if (! isset($answered[$finding->id])) {
                $drop('unanswered');
            }
        }

        $kept = $this->resolveOverlaps($kept, $drop);
        $kept = $this->applyCaps($kept, $input, $drop);
        $kept = $this->applyPageShare($kept, $input, $drop);

        return new ValidatedReview($this->inFormOrder($kept, $input), $dropped, $checked);
    }

    /**
     * The verifier's second pass over the kept suggestions (numbered s1,
     * s2… in their order, as Studio::verifyEdits() sent them): `keep`,
     * `fix` (a corrected replacement, and alternatives if it gives any,
     * each checked again as the first were, `fit` included) or `drop`
     * (into `checked`, by the verifier). A suggestion it didn't answer is
     * kept as it was; so is a `fix` of one with no words to fix. A fix
     * that fails a check drops the suggestion, counted by the reason.
     */
    public function verify(ValidatedReview $review, SuggestionReply $verdicts, ReviewInput $input): ValidatedReview
    {
        $numbered = [];

        foreach ($review->suggestions as $i => $suggestion) {
            $numbered['s'.($i + 1)] = $suggestion;
        }

        $answers = [];

        foreach ($verdicts->items as ['item' => $item]) {
            $id = is_string($item['id'] ?? null) ? $item['id'] : '';

            if (isset($numbered[$id]) && ! isset($answers[$id])) {
                $answers[$id] = $item;
            }
        }

        $dropped = $review->dropped;
        $checked = $review->checked;
        $verified = [];
        $kept = [];

        foreach ($numbered as $number => $suggestion) {
            $answer = $answers[$number] ?? null;
            $verdict = is_string($answer['verdict'] ?? null) ? strtolower(trim($answer['verdict'])) : null;

            if ($answer === null || ! in_array($verdict, ['keep', 'fix', 'drop'], true)) {
                $kept[] = $suggestion;

                continue;
            }

            if ($verdict === 'drop') {
                $checked[] = ['id' => $suggestion->id, 'passage' => $suggestion->anchor->passage, 'reason' => is_string($answer['reason'] ?? null) ? trim(mb_substr($answer['reason'], 0, 200)) : '', 'by' => 'verifier'];
                $dropped['verifier'] = ($dropped['verifier'] ?? 0) + 1;

                continue;
            }

            $text = is_string($answer['replacement'] ?? null) && trim($answer['replacement']) !== '' ? trim($answer['replacement']) : null;

            if ($verdict === 'keep' || $text === null || $suggestion->replacement === null || $suggestion->fact !== null) {
                $verified[$suggestion->id] = 'keep';
                $kept[] = $suggestion;

                continue;
            }

            $tokens = VerifyPrompt::tokens($input, $input->batches()[$input->batchFor($suggestion->anchor)] ?? $input->batches()[0]);
            $restore = fn (string $value) => ReviewPrompt::restoreLinks(trim($value), $tokens, $input->digest);
            $cited = $suggestion->reason->entry !== null ? $this->digestIdOf($suggestion->reason->entry, $input) : null;
            $quote = $suggestion->anchor->quote->exact ?? '';
            $asset = $suggestion->anchor->scope === AnchorScope::Asset;
            $check = fn (string $value) => $asset ? null : $this->checkText($suggestion->category, $suggestion->anchor, $quote, $value, $input, $cited);
            $replacement = $restore($text);
            $replacement = $replacement !== null && $asset ? self::alt($replacement) : $replacement;
            $problem = $replacement === null ? 'link' : $check($replacement);

            if ($problem !== null || $replacement === null) {
                $dropped[$problem ?? 'link'] = ($dropped[$problem ?? 'link'] ?? 0) + 1;

                continue;
            }

            $alternatives = [];
            $given = is_array($answer['alternatives'] ?? null) ? $answer['alternatives'] : $suggestion->alternatives;

            foreach ($given as $alternative) {
                $alternative = is_string($alternative) && trim($alternative) !== '' ? $restore($alternative) : null;
                $alternative = $alternative !== null && $asset ? self::alt($alternative) : $alternative;

                if ($alternative !== null && $alternative !== $replacement && ! in_array($alternative, $alternatives, true) && $check($alternative) === null) {
                    $alternatives[] = $alternative;
                }
            }

            $verified[$suggestion->id] = 'fix';
            $kept[] = new Suggestion($suggestion->id, $suggestion->category, $suggestion->anchor, $suggestion->reason, $replacement, array_slice($alternatives, 0, Suggestion::ALTERNATIVES), $suggestion->fact, $suggestion->link, $suggestion->finding, $suggestion->free, $suggestion->state);
        }

        return new ValidatedReview($kept, $dropped, $checked, $verified);
    }

    /**
     * Whether a new version of a suggestion's words passes the same checks
     * as the first ("Write another").
     */
    public function acceptsVersion(Suggestion $suggestion, string $text, ReviewInput $input): bool
    {
        $quote = $suggestion->anchor->quote->exact ?? '';

        return $this->checkText($suggestion->category, $suggestion->anchor, $quote, $text, $input, $suggestion->reason->entry !== null ? $this->digestIdOf($suggestion->reason->entry, $input) : null) === null;
    }

    /**
     * One item checked: the suggestion with where it is, or why it's dropped.
     *
     * @param  array<string, mixed>  $item
     * @return array{suggestion: Suggestion, at: int, length: int}|string
     */
    private function one(array $item, ?Finding $finding, ReviewBatch $batch, ReviewInput $input, SuggestionReply $reply): array|string
    {
        $category = $finding->category ?? Category::tryFrom(is_string($item['category'] ?? null) ? $item['category'] : '');

        if ($category === null) {
            return 'unreadable';
        }

        if ($finding === null && $category === Category::FactToCheck && ! $input->context->options->claims) {
            return 'claims';
        }

        if ($category === Category::Voice && trim($input->writer->voice) === '') {
            return 'voice';
        }

        if ($finding !== null) {
            $anchor = $finding->anchor;
            [$at, $length] = $this->where($anchor, $input);
        } else {
            $located = $this->locate($item, $batch, $input);

            if ($located === null) {
                return 'anchor';
            }

            [$anchor, $at, $length] = $located;
        }

        $quoteText = $anchor->quote->exact ?? '';

        if (str_contains($quoteText, 'ask:') || str_contains($quoteText, 'check:') || $this->touchesLinkMarker($anchor, $at, $length, $input)) {
            return 'markers';
        }

        $reason = $this->reason($item, $finding, $input);
        $citedEntry = is_array($item['source'] ?? null) && is_string($item['source']['entry'] ?? null) && $input->digest->get($item['source']['entry']) !== null ? $item['source']['entry'] : null;
        $tokens = ReviewPrompt::links($input, $batch);
        $restore = fn (?string $text) => $text === null ? null : ReviewPrompt::restoreLinks(trim($text), $tokens, $input->digest);
        $replacement = is_string($item['replacement'] ?? null) && trim($item['replacement']) !== '' ? $restore($item['replacement']) : null;

        if (is_string($item['replacement'] ?? null) && trim($item['replacement']) !== '' && $replacement === null) {
            return 'link';
        }

        $link = null;

        if (is_array($item['link'] ?? null) && is_string($item['link']['entry'] ?? null)) {
            $entry = $input->digest->get($item['link']['entry']);

            if ($entry === null || $entry->link === null || $input->context->gaps->targets === null) {
                return 'link';
            }

            $link = new LinkChange($entry->link, $entry->title, $entry->url, false);
        } elseif ($finding !== null && is_array($finding->meta['candidates'] ?? null) && is_array($finding->meta['candidates'][0] ?? null)) {
            $candidate = $finding->meta['candidates'][0];
            $link = new LinkChange($candidate['value'] ?? null, is_string($candidate['title'] ?? null) ? $candidate['title'] : '', is_string($candidate['url'] ?? null) ? $candidate['url'] : null);
        }

        $fact = null;
        $alternatives = [];

        if ($category === Category::FactToCheck) {
            $fact = $this->fact($item, $finding, $quoteText);

            if ($fact === null) {
                return 'facts';
            }

            $replacement = null;
        } elseif ($anchor->scope === AnchorScope::Asset) {
            if ($replacement === null && ($finding === null || in_array($finding->id, $reply->attached, true))) {
                return 'scope';
            }

            // Kept with no picture to look at: "Describe the image yourself".
            $replacement = $replacement !== null ? self::alt($replacement) : null;
            $alternatives = array_values(array_map(fn (string $text) => self::alt($text), array_filter(array_map(fn ($text) => is_string($text) ? $restore($text) : null, is_array($item['alternatives'] ?? null) ? $item['alternatives'] : []))));
        } else {
            // A kept candidate whose fix isn't words (a link field, a broken link) may come with none.
            if ($replacement === null && $link === null && ! ($finding !== null && ($finding->needs === Needs::Nothing || $finding->category === Category::Link))) {
                return 'scope';
            }

            if ($replacement !== null && ($problem = $this->checkText($category, $anchor, $quoteText, $replacement, $input, $citedEntry)) !== null) {
                return $problem;
            }

            foreach (is_array($item['alternatives'] ?? null) ? $item['alternatives'] : [] as $alternative) {
                $alternative = is_string($alternative) ? $restore($alternative) : null;

                if ($alternative !== null && $alternative !== $replacement && ! in_array($alternative, $alternatives, true) && $this->checkText($category, $anchor, $quoteText, $alternative, $input, $citedEntry) === null) {
                    $alternatives[] = $alternative;
                }
            }
        }

        $id = $finding->id ?? $anchor->key($category);

        return [
            'suggestion' => new Suggestion($id, $category, $anchor, $reason, $replacement, array_slice($alternatives, 0, Suggestion::ALTERNATIVES), $fact, $link, $finding?->id, false),
            'at' => $at,
            'length' => $length,
        ];
    }

    /**
     * The problem with a replacement for a quote, or null when it passes.
     */
    private function checkText(Category $category, Anchor $anchor, string $quote, string $replacement, ReviewInput $input, ?string $citedEntry): ?string
    {
        $sources = [$this->pageText($input)];

        if ($citedEntry !== null) {
            $sources[] = $input->digest->text($citedEntry);
        }

        if ($anchor->scope === AnchorScope::Field && $quote === '') {
            $quote = $this->fieldText($anchor, $input);
        }

        [$min, $max] = match ($category) {
            Category::Clarity => [0.2, 1.5],
            Category::Duplicate => [0.0, 1.5],
            Category::Seo => [0.0, 100.0],
            Category::Link => [0.3, 3.0],
            default => [0.3, 1.5],
        };

        $problems = $this->scoped->check($quote, $replacement, $sources, $min, $max);

        if ($problems !== []) {
            return $problems[0];
        }

        $seo = $this->seoAt($anchor, $input);

        if ($category === Category::Seo && $seo !== null && mb_strlen($replacement) > $seo->limit) {
            return 'size';
        }

        // An SEO value the page inherits (another field's text, a section's
        // default) that fits stays as the site set it up (decision 11).
        if ($seo !== null && $seo->inherited() && $seo->checkable() && ! $seo->isEmpty() && ! $seo->tooLong()) {
            return 'inherited';
        }

        return $this->fits($category, $anchor, $replacement, $input) ? null : 'fit';
    }

    /**
     * Whether a replacement reads as whole sentences where it goes
     * (Anchor\SentenceFit), in its block of the field's text.
     */
    private function fits(Category $category, Anchor $anchor, string $replacement, ReviewInput $input): bool
    {
        $text = $input->context->textAt($anchor->path->toString());

        if ($text === null || $anchor->scope === AnchorScope::Asset) {
            return true;
        }

        if ($anchor->scope === AnchorScope::Field) {
            return SentenceFit::fits($text->plain, 0, mb_strlen($text->plain), $replacement, $category === Category::Duplicate);
        }

        [$at, $length] = $this->where($anchor, $input);
        $block = $text->blockAt($at);

        if ($length === 0) {
            return true;
        }

        if ($block === null) {
            return SentenceFit::fits($text->plain, $at, $length, $replacement, $category === Category::Duplicate);
        }

        [$start] = $text->blocks[$block];

        return SentenceFit::fits($text->block($block), $at - $start, $length, $replacement, $category === Category::Duplicate);
    }

    /**
     * Where a model's suggestion points: its unit and quote turned into
     * an anchor on the field, with the range in the field's plain text.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: Anchor, 1: int, 2: int}|null
     */
    private function locate(array $item, ReviewBatch $batch, ReviewInput $input): ?array
    {
        $unitId = is_string($item['unit'] ?? null) ? $item['unit'] : '';
        $unit = $batch->unit($unitId);

        if ($unit === null) {
            return null;
        }

        $quote = is_string($item['quote'] ?? null) ? NormalisedText::string((string) preg_replace('/\]\([^)]*\)/u', ']', $item['quote']), true) : '';
        $occurrence = is_int($item['occurrence'] ?? null) ? $item['occurrence'] : null;

        foreach ($this->textsOf($unit, $input) as [$text, $unitPlain]) {
            if ($quote === '') {
                $short = mb_strlen($text->plain) <= self::SHORT_FIELD && in_array($unit->kind, [UnitKind::Text, UnitKind::Prose], true);

                if ($short || $this->isSeo($text->visit->path->toString(), $input)) {
                    return [$text->fieldAnchor(), 0, mb_strlen($text->plain)];
                }

                continue;
            }

            $start = mb_strpos($text->plain, $unitPlain);
            $start = $start === false ? 0 : $start;
            $span = $unitPlain !== '' && mb_strpos($text->plain, $unitPlain) !== false ? $unitPlain : $text->plain;
            $match = $this->quotes->find(new TextQuote(mb_substr($quote, 0, TextQuote::MAX_EXACT)), $span, $occurrence);

            if ($match === null) {
                continue;
            }

            $at = $start + $match->offset;

            if ($text->blockAt($at) === null || $text->blockAt($at) !== $text->blockAt($at + max(0, $match->length - 1))) {
                return null;
            }

            return [$text->anchor($at, $match->length), $at, $match->length];
        }

        return null;
    }

    /**
     * The field texts a unit's words are in, each with the unit's own
     * words there: a row's fields one by one, a section within its field.
     *
     * @return list<array{0: CheckText, 1: string}>
     */
    private function textsOf(Unit $unit, ReviewInput $input): array
    {
        $found = [];

        if ($unit->kind === UnitKind::Row) {
            foreach ($unit->pieces as $piece) {
                $text = $piece->field !== null ? $input->context->textAt($unit->path->with($piece->field)->toString()) : null;

                if ($text !== null) {
                    $found[] = [$text, NormalisedText::string($piece->markdown, true)];
                }
            }

            return $found;
        }

        $text = $input->context->textAt($unit->path->toString());

        return $text === null ? [] : [[$text, NormalisedText::string($unit->markdown, true)]];
    }

    /**
     * Where an anchor's range is in its field's plain text.
     *
     * @return array{0: int, 1: int}
     */
    private function where(Anchor $anchor, ReviewInput $input): array
    {
        $text = $input->context->textAt($anchor->path->toString());

        if ($text === null || $anchor->scope !== AnchorScope::Range || $anchor->quote === null) {
            return [0, $text === null ? 0 : mb_strlen($text->plain)];
        }

        $match = $this->quotes->find($anchor->quote, $text->plain, $anchor->occurrence);

        return $match === null ? [0, 0] : [$match->offset, $match->length];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function reason(array $item, ?Finding $finding, ReviewInput $input): Reason
    {
        $text = is_string($item['reason'] ?? null) ? trim(mb_substr($item['reason'], 0, 400)) : '';
        $source = is_array($item['source'] ?? null) ? $item['source'] : [];
        $kind = is_string($source['kind'] ?? null) ? $source['kind'] : 'general';

        if ($finding !== null && in_array($kind, ['finding', 'general', 'image'], true)) {
            return new Reason($text, $kind === 'image' ? ReasonSource::Image : ReasonSource::Check, $finding->kind, message: $finding->message);
        }

        return match ($kind) {
            'voice-guide' => ($heading = $this->heading(is_string($source['heading'] ?? null) ? $source['heading'] : '', $input->writer->voice)) !== null
                ? new Reason($text, ReasonSource::VoiceGuide, $heading)
                : new Reason($text, ReasonSource::General),
            'kind' => new Reason($text, ReasonSource::Kind, $input->writer->kind->title),
            'house' => new Reason($text, ReasonSource::House),
            'image' => new Reason($text, ReasonSource::Image),
            'site-entry' => is_string($source['entry'] ?? null) && ($entry = $input->digest->get($source['entry'])) !== null
                ? new Reason($text, ReasonSource::SiteEntry, null, $entry->entry?->key() ?? (is_scalar($entry->link) ? (string) $entry->link : null), title: $entry->title)
                : new Reason($text, ReasonSource::General),
            'finding' => new Reason($text, $finding !== null ? ReasonSource::Check : ReasonSource::General),
            default => new Reason($text, ReasonSource::General),
        };
    }

    /** The voice guide's heading as written, if `$claimed` is one of them. */
    private function heading(string $claimed, string $voice): ?string
    {
        $want = implode(' ', NormalisedText::words($claimed));

        if ($want === '' || preg_match_all('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/mu', $voice, $headings) === 0) {
            return null;
        }

        foreach ($headings[1] as $heading) {
            if (implode(' ', NormalisedText::words($heading)) === $want) {
                return trim($heading);
            }
        }

        return null;
    }

    /**
     * A Fact to check's template and version without, checked against
     * the quote; a free finding's template when the model gave none.
     *
     * @param  array<string, mixed>  $item
     */
    private function fact(array $item, ?Finding $finding, string $quote): ?FactCheck
    {
        $fact = is_array($item['fact'] ?? null) ? $item['fact'] : [];
        $meta = $finding !== null ? $finding->meta : [];
        $template = is_string($fact['template'] ?? null) ? trim($fact['template']) : (is_string($meta['template'] ?? null) ? $meta['template'] : null);
        $answer = AnswerKind::tryFrom(is_string($fact['answer'] ?? null) ? $fact['answer'] : (is_string($meta['answer'] ?? null) ? $meta['answer'] : '')) ?? AnswerKind::Text;

        if ($template === null || substr_count($template, FactCheck::ANSWER) !== 1 || ! self::oneSpanRemoved($quote, $template)) {
            return null;
        }

        $without = is_string($fact['without'] ?? null) && trim($fact['without']) !== '' ? trim($fact['without']) : null;

        if ($without !== null && ($this->sources->unsourced($without, [$quote]) !== [] || array_diff(NormalisedText::words($without), NormalisedText::words($quote)) !== [])) {
            $without = null;
        }

        $ask = is_string($fact['ask'] ?? null) ? trim(mb_substr($fact['ask'], 0, 80)) : '';

        return new FactCheck($ask, $template, $without, $answer);
    }

    /** Whether a template is the quote with exactly one span of words replaced by `{answer}`. */
    private static function oneSpanRemoved(string $quote, string $template): bool
    {
        [$before, $after] = explode(FactCheck::ANSWER, $template, 2);
        $q = NormalisedText::words($quote);
        $b = NormalisedText::words($before);
        $a = NormalisedText::words($after);

        if (count($b) + count($a) >= count($q)) {
            return false;
        }

        return array_slice($q, 0, count($b)) === $b && ($a === [] || array_slice($q, -count($a)) === $a);
    }

    /** Alt text: at most ALT_LIMIT characters, cut at a word, without "Image of". */
    private static function alt(string $text): string
    {
        $text = trim((string) preg_replace('/^(?:an?\s+)?(?:image|picture|photo|photograph)\s+(?:of|showing)\s+/iu', '', trim($text, " \t\n\"'“”")));
        $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);

        if (mb_strlen($text) <= self::ALT_LIMIT) {
            return $text;
        }

        $cut = mb_strrpos(mb_substr($text, 0, self::ALT_LIMIT), ' ');

        return rtrim(mb_substr($text, 0, $cut === false ? self::ALT_LIMIT : $cut), ' ,;:-');
    }

    /** Whether a range takes in the words of a `#gw-link:` link, which only the editor may finish. */
    private function touchesLinkMarker(Anchor $anchor, int $at, int $length, ReviewInput $input): bool
    {
        $text = $input->context->textAt($anchor->path->toString());

        if ($text === null || $anchor->scope !== AnchorScope::Range) {
            return false;
        }

        foreach (Markers::links($text->markdown) as $link) {
            $words = NormalisedText::string($link['words'], true);
            $from = 0;

            while ($words !== '' && ($found = mb_strpos($text->plain, $words, $from)) !== false) {
                if ($found < $at + $length && $at < $found + mb_strlen($words)) {
                    return true;
                }

                $from = $found + 1;
            }
        }

        return false;
    }

    private function pageText(ReviewInput $input): string
    {
        return $input->context->gaps->entry->title()."\n\n".implode("\n\n", array_map(fn (CheckText $text) => $text->markdown, $input->context->texts()));
    }

    private function fieldText(Anchor $anchor, ReviewInput $input): string
    {
        return $input->context->textAt($anchor->path->toString())->plain ?? '';
    }

    private function isSeo(string $path, ReviewInput $input): bool
    {
        $context = $input->context->gaps;

        foreach ($context->seo?->in($context->schema, $context->entry) ?? [] as $field) {
            if ($field->path->toString() === $path) {
                return true;
            }
        }

        return false;
    }

    private function seoAt(Anchor $anchor, ReviewInput $input): ?SeoField
    {
        $context = $input->context->gaps;

        foreach ($context->seo?->in($context->schema, $context->entry) ?? [] as $field) {
            if ($field instanceof SeoField && $field->path->equals($anchor->path)) {
                return $field;
            }
        }

        return null;
    }

    private function digestIdOf(string $entryKey, ReviewInput $input): ?string
    {
        foreach ($input->digest->all() as $id => $entry) {
            if ($entry->entry?->key() === $entryKey) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>  $kept
     * @param  callable(string): void  $drop
     * @return list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>
     */
    private function resolveOverlaps(array $kept, callable $drop): array
    {
        $better = function (array $a, array $b): bool {
            if ($a['own'] !== $b['own']) {
                return ! $a['own'];
            }

            if ($a['suggestion']->category->rank() !== $b['suggestion']->category->rank()) {
                return $a['suggestion']->category->rank() < $b['suggestion']->category->rank();
            }

            return $a['length'] !== $b['length'] ? $a['length'] < $b['length'] : $a['order'] <= $b['order'];
        };

        $out = [];

        foreach ($kept as $candidate) {
            $s = $candidate['suggestion'];

            foreach ($out as $i => $other) {
                $o = $other['suggestion'];
                $samePlace = $o->anchor->path->equals($s->anchor->path) && $o->anchor->scope === $s->anchor->scope;
                $overlap = $samePlace && match ($s->anchor->scope) {
                    AnchorScope::Range => $candidate['at'] < $other['at'] + max(1, $other['length']) && $other['at'] < $candidate['at'] + max(1, $candidate['length']),
                    AnchorScope::Asset => $o->anchor->asset?->key() === $s->anchor->asset?->key(),
                    AnchorScope::Field => true,
                };

                if (! $overlap) {
                    continue;
                }

                if ($better($candidate, $other)) {
                    unset($out[$i]);
                    $drop('overlap');

                    continue;
                }

                $drop('overlap');

                continue 2;
            }

            $out[] = $candidate;
        }

        return array_values($out);
    }

    /**
     * The model's own suggestions: at most PER_UNIT in a field and `cap`
     * a call, by rank then page order.
     *
     * @param  list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>  $kept
     * @param  callable(string): void  $drop
     * @return list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>
     */
    private function applyCaps(array $kept, ReviewInput $input, callable $drop): array
    {
        $own = array_filter($kept, fn (array $item) => $item['own']);
        uasort($own, fn (array $a, array $b) => [$a['suggestion']->category->rank(), $a['order']] <=> [$b['suggestion']->category->rank(), $b['order']]);
        $perField = [];
        $count = 0;
        $limit = $input->cap * $input->calls();
        $removed = [];

        foreach ($own as $i => $item) {
            $path = $item['suggestion']->anchor->path->toString();
            $perField[$path] = ($perField[$path] ?? 0) + 1;

            if ($perField[$path] > self::PER_UNIT || ++$count > $limit) {
                $removed[$i] = true;
                $drop('cap');
            }
        }

        return array_values(array_filter($kept, fn (array $item, int $i) => ! isset($removed[$i]), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * At most PAGE_SHARE of the page's words changed by the model's own
     * suggestions (fixes for findings don't count): the lowest-ranked go
     * first.
     *
     * @param  list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>  $kept
     * @param  callable(string): void  $drop
     * @return list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>
     */
    private function applyPageShare(array $kept, ReviewInput $input, callable $drop): array
    {
        $page = max(1, array_sum(array_map(fn (CheckText $text) => count(NormalisedText::words($text->plain)), $input->context->texts())));
        $changed = fn (array $items) => array_sum(array_map(fn (array $item) => $item['own'] && $item['suggestion']->replacement !== null && $item['suggestion']->anchor->scope !== AnchorScope::Asset ? count(NormalisedText::words($item['suggestion']->anchor->quote->exact ?? '')) : 0, $items));

        while ($changed($kept) > $page * self::PAGE_SHARE) {
            $worst = null;

            foreach ($kept as $i => $item) {
                if ($item['own'] && ($worst === null || [$item['suggestion']->category->rank(), $item['order']] > [$kept[$worst]['suggestion']->category->rank(), $kept[$worst]['order']])) {
                    $worst = $i;
                }
            }

            if ($worst === null) {
                break;
            }

            unset($kept[$worst]);
            $kept = array_values($kept);
            $drop('page-share');
        }

        return $kept;
    }

    /**
     * @param  list<array{suggestion: Suggestion, at: int, length: int, own: bool, order: int}>  $kept
     * @return list<Suggestion>
     */
    private function inFormOrder(array $kept, ReviewInput $input): array
    {
        $order = [];

        foreach (Walk::entry($input->context->gaps->schema, $input->context->gaps->entry) as $visit) {
            $order[$visit->path->toString()] ??= count($order);
        }

        usort($kept, fn (array $a, array $b) => [$order[$a['suggestion']->anchor->path->toString()] ?? PHP_INT_MAX, $a['at'], $a['order']] <=> [$order[$b['suggestion']->anchor->path->toString()] ?? PHP_INT_MAX, $b['at'], $b['order']]);

        return array_values(array_map(fn (array $item) => $item['suggestion'], $kept));
    }
}
