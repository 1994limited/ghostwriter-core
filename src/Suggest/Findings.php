<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapReport;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\ClosingDates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\EmptyLinkText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\ExternalLinks;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\LongSentences;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\Overlaps;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\PastYears;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\RelativeTime;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\StatedCounts;

/**
 * The free half of Suggest edits and the whole of Content to revisit:
 * every check, and the Gaps detectors whose finds are suggestions (broken
 * links, missing alt text, SEO length, an empty SEO description), over an
 * entry's current values. It never calls a model and never makes a
 * request.
 *
 *     $report = Findings::standard()->report($context);
 *     $report->findings;   // list<Finding>, in form order
 *     $report->gaps;       // Finish this page's gaps, for the revisit list
 *
 * Findings that a decision keeps quiet (CheckContext::$quieted) are left
 * out. Where two findings share an id, the first is kept.
 */
final class Findings
{
    /** @var list<Check> */
    private readonly array $checks;

    private readonly GapFinder $gaps;

    /**
     * @param  array<int, Check>  $checks
     */
    public function __construct(array $checks, ?GapFinder $gaps = null)
    {
        $this->checks = array_values($checks);
        $this->gaps = $gaps ?? GapFinder::standard();
    }

    public static function standard(): self
    {
        return new self([
            new PastYears,
            new RelativeTime,
            new ClosingDates,
            new StatedCounts,
            new LongSentences,
            new EmptyLinkText,
            new Overlaps,
            new ExternalLinks,
        ]);
    }

    /** The same without some checks, by kind: the revisit scan drops 'overlap'. */
    public function without(string ...$kinds): self
    {
        return new self(array_values(array_filter($this->checks, fn (Check $check) => array_intersect($check->kinds(), $kinds) === [])), $this->gaps);
    }

    /** The same with more checks (an addon's own). */
    public function with(Check ...$checks): self
    {
        return new self([...$this->checks, ...array_values($checks)], $this->gaps);
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<Finding> In form order.
     */
    public function find(CheckContext $context): array
    {
        return $this->report($context)->findings;
    }

    public function report(CheckContext $context): FindingReport
    {
        $gaps = $this->withoutFilledSeo($this->gaps->find($context->gaps), $context);
        $found = [];

        foreach ($this->checks as $check) {
            foreach ($check->find($context) as $finding) {
                $found[] = $finding;
            }
        }

        // An SEO description SeoMissing finds is said once, by it.
        $missing = [];

        foreach ($gaps->ofKind(GapKind::SeoMissing) as $gap) {
            $missing[$gap->path->toString()] = true;
        }

        foreach ($gaps->all() as $gap) {
            if ($gap->kind === GapKind::Expected && isset($missing[$gap->path->toString()])) {
                continue;
            }

            $finding = $this->fromGap($gap, $context);

            if ($finding !== null) {
                $found[] = $finding;
            }
        }

        $kept = [];

        foreach ($found as $finding) {
            if (! isset($kept[$finding->id]) && ! $context->quieted->coversFinding($finding, $context->now)) {
                $kept[$finding->id] = $finding;
            }
        }

        return new FindingReport($this->inFormOrder(array_values($kept), $context), $gaps);
    }

    /**
     * The gaps without an "expected" one on an SEO field the page fills
     * all the same: one inheriting another field's text or a section's
     * default, or filled by a template, or switched off. It's neither a
     * suggestion nor an empty field in the revisit list (decision 11).
     */
    private function withoutFilledSeo(GapReport $gaps, CheckContext $context): GapReport
    {
        $expected = $gaps->ofKind(GapKind::Expected);
        $seo = $expected === [] ? [] : ($context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? []);
        $filled = [];

        foreach ($seo as $field) {
            if (! $field->isEmpty()) {
                $filled[$field->path->toString()] = true;
            }
        }

        if ($filled === []) {
            return $gaps;
        }

        return new GapReport(array_values(array_filter($gaps->all(), fn (Gap $gap) => $gap->kind !== GapKind::Expected || ! isset($filled[$gap->path->toString()]))));
    }

    /** A Finish gap that is also a suggestion, as a finding; null for the rest. */
    private function fromGap(Gap $gap, CheckContext $context): ?Finding
    {
        return match ($gap->kind) {
            GapKind::LinkBroken => $this->brokenLink($gap, $context),
            GapKind::MissingAlt => $this->missingAlt($gap),
            GapKind::SeoLength => $this->seoLength($gap, $context),
            GapKind::Expected => $this->emptySeo($gap, $context),
            GapKind::SeoMissing => $this->seoMissing($gap, $context),
            GapKind::HeadingLong => $this->longHeading($gap, $context),
            GapKind::FewLinks => $this->fewLinks($gap, $context),
            default => null,
        };
    }

    private function brokenLink(Gap $gap, CheckContext $context): Finding
    {
        $words = is_string($gap->meta['words'] ?? null) ? $gap->meta['words'] : '';
        $candidates = $context->gaps->targets !== null && trim($words) !== '' ? $context->gaps->targets->search($words, 3) : [];
        $meta = ['href' => $gap->hint, 'candidates' => array_map(fn (LinkTarget $target) => $target->toArray(), $candidates)];
        $text = $context->textAt($gap->path->toString());

        if (($gap->meta['inline'] ?? false) === true && $text !== null && trim($words) !== '') {
            $at = $this->nth($text->plain, NormalisedText::string($words, true), $gap->occurrence);

            if ($at !== null) {
                $anchor = $text->anchor($at, mb_strlen(NormalisedText::string($words, true)));

                return Finding::make(Category::Link, 'link-broken', $anchor, Needs::Words, new Message('suggest.finding.link-broken', ['quote' => $anchor->quote?->exact]), $meta + ['inline' => true]);
            }
        }

        $anchor = new Anchor(AnchorScope::Field, $gap->path, $gap->label, fieldHash: $gap->id, passage: Anchor::hash($gap->id));

        return Finding::make(Category::Link, 'link-broken', $anchor, Needs::Nothing, new Message('suggest.finding.link-broken-field', ['label' => $gap->label]), $meta + ['inline' => false]);
    }

    private function missingAlt(Gap $gap): Finding
    {
        $asset = is_array($gap->meta['asset'] ?? null) ? $gap->meta['asset'] : [];
        $id = $asset['id'] ?? null;
        $ref = new AssetRef(is_string($asset['volume'] ?? null) ? $asset['volume'] : '', is_string($asset['path'] ?? null) ? $asset['path'] : '', is_int($id) || is_string($id) ? $id : null);
        $anchor = new Anchor(AnchorScope::Asset, $gap->path, $gap->label, asset: $ref, passage: Anchor::hash($ref->key().' alt:'));

        return Finding::make(Category::Accessibility, 'missing-alt', $anchor, Needs::Words, new Message('suggest.finding.missing-alt', [
            'label' => $gap->label,
            'filename' => $ref->filename(),
        ]), ['filename' => $ref->filename(), 'inline' => $gap->meta['inline'] ?? false]);
    }

    private function seoLength(Gap $gap, CheckContext $context): Finding
    {
        $text = $context->textAt($gap->path->toString());
        $anchor = $text?->fieldAnchor() ?? new Anchor(AnchorScope::Field, $gap->path, $gap->label);

        return Finding::make(Category::Seo, 'seo-length', $anchor, Needs::Words, new Message('suggest.finding.seo-length', [
            'label' => $gap->label,
            'length' => is_int($gap->meta['length'] ?? null) ? $gap->meta['length'] : 0,
            'limit' => is_int($gap->meta['limit'] ?? null) ? $gap->meta['limit'] : 0,
        ]), array_intersect_key($gap->meta, array_flip(['role', 'limit', 'length', 'writable', 'inheritsFrom', 'source'])));
    }

    /**
     * An empty SEO description most pages like this fill: one the page
     * prints nothing for, not one it inherits text for (a fallback field,
     * a section's default) or one a template fills.
     */
    private function emptySeo(Gap $gap, CheckContext $context): ?Finding
    {
        $seo = $context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? [];
        $field = null;

        foreach ($seo as $candidate) {
            if ($candidate->path->equals($gap->path) && $candidate->role === SeoField::DESCRIPTION && $candidate->writable && $candidate->isEmpty()) {
                $field = $candidate;
            }
        }

        if ($field === null) {
            return null;
        }

        $anchor = new Anchor(AnchorScope::Field, $gap->path, $gap->label, fieldHash: Anchor::hash(''), passage: Anchor::hash(''));

        return Finding::make(Category::Seo, 'seo-empty', $anchor, Needs::Words, new Message('suggest.finding.seo-empty', ['label' => $gap->label]), [
            'role' => $field->role,
            'limit' => $field->limit,
            'source' => $field->source->value,
        ]);
    }

    /**
     * An SEO description that's empty or too short to say much (SeoMissing),
     * one the page could have its own of. A description inherited from a
     * field that fits never gets here (decision 11). The reviewer writes
     * one from the page; the validator checks it as any SEO value.
     */
    private function seoMissing(Gap $gap, CheckContext $context): Finding
    {
        $text = $context->textAt($gap->path->toString());
        // Empty as the page prints it: an inherited description is read
        // from its source, not from the SEO field's own (empty) value.
        $empty = ! is_int($gap->meta['length'] ?? null) || $gap->meta['length'] === 0;
        $anchor = $text?->fieldAnchor() ?? new Anchor(AnchorScope::Field, $gap->path, $gap->label, fieldHash: Anchor::hash(''), passage: Anchor::hash(''));
        $inherited = ($gap->meta['source'] ?? null) === 'field' && is_string($gap->meta['inheritsFrom'] ?? null);
        $key = match (true) {
            $empty && $inherited => 'suggest.finding.seo-missing-inherited',
            $empty => 'suggest.finding.seo-missing',
            default => 'suggest.finding.seo-missing-short',
        };

        return Finding::make(Category::Seo, 'seo-missing', $anchor, Needs::Words, new Message($key, [
            'label' => $gap->label,
            'length' => is_int($gap->meta['length'] ?? null) ? $gap->meta['length'] : 0,
            'min' => is_int($gap->meta['min'] ?? null) ? $gap->meta['min'] : 0,
            'max' => is_int($gap->meta['max'] ?? null) ? $gap->meta['max'] : 0,
            'field' => is_string($gap->meta['inheritsFrom'] ?? null) ? $gap->meta['inheritsFrom'] : '',
        ]), array_intersect_key($gap->meta, array_flip(['role', 'limit', 'length', 'min', 'max', 'writable', 'inheritsFrom', 'source'])) + ['empty' => $empty]);
    }

    /**
     * A heading over 70 characters (LongHeadings), anchored on its words:
     * the reviewer writes a shorter one in the voice, or drops it.
     */
    private function longHeading(Gap $gap, CheckContext $context): ?Finding
    {
        $text = $context->textAt($gap->path->toString());
        $words = (string) $gap->hint;
        $at = $text === null || $words === '' ? null : $this->nth($text->plain, NormalisedText::string($words, true), $gap->occurrence);

        if ($text === null || $at === null) {
            return null;
        }

        $anchor = $text->anchor($at, mb_strlen(NormalisedText::string($words, true)));

        return Finding::make(Category::Seo, 'heading-long', $anchor, Needs::Words, new Message('suggest.finding.heading-long', [
            'label' => $gap->label,
            'length' => is_int($gap->meta['length'] ?? null) ? $gap->meta['length'] : mb_strlen($words),
        ]), ['length' => $gap->meta['length'] ?? mb_strlen($words), 'limit' => $gap->meta['limit'] ?? null, 'heading' => true]);
    }

    /**
     * No link to the site's own pages on a long page (FewLinks): not shown
     * on its own (there's nothing to accept yet), but a candidate for the
     * reviewer, who is shown pages it could link to (SiteDigest) and
     * proposes the links as suggestions of its own.
     */
    private function fewLinks(Gap $gap, CheckContext $context): Finding
    {
        $text = $context->textAt($gap->path->toString());
        $anchor = $text !== null
            ? new Anchor(AnchorScope::Field, $gap->path, $gap->label, fieldHash: Anchor::hash($text->plain), passage: Anchor::hash('few-links'))
            : new Anchor(AnchorScope::Field, $gap->path, $gap->label, passage: Anchor::hash('few-links'));

        return Finding::make(Category::Link, 'few-links', $anchor, Needs::Nothing, new Message('suggest.finding.few-links', [
            'label' => $gap->label,
            'words' => is_int($gap->meta['words'] ?? null) ? $gap->meta['words'] : 0,
        ]), ['words' => $gap->meta['words'] ?? null], alone: false);
    }

    /** Where the nth (from 0) occurrence of a string is, in characters. */
    private function nth(string $text, string $needle, int $n): ?int
    {
        $from = 0;

        for ($i = 0; ($at = mb_strpos($text, $needle, $from)) !== false; $i++) {
            if ($i === $n) {
                return $at;
            }

            $from = $at + 1;
        }

        return null;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function inFormOrder(array $findings, CheckContext $context): array
    {
        $order = [];

        foreach (Walk::entry($context->gaps->schema, $context->gaps->entry) as $visit) {
            $order[$visit->path->toString()] ??= count($order);
        }

        $finder = new QuoteFinder;
        $offset = function (Finding $finding) use ($context, $finder): int {
            $text = $finding->anchor->quote === null ? null : $context->textAt($finding->anchor->path->toString());

            return $text === null || $finding->anchor->quote === null ? 0 : ($finder->find($finding->anchor->quote, $text->plain, $finding->anchor->occurrence)->offset ?? 0);
        };

        $keyed = array_map(fn (Finding $finding, int $i) => [$order[$finding->anchor->path->toString()] ?? PHP_INT_MAX, $offset($finding), $i, $finding], $findings, array_keys($findings));
        usort($keyed, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(fn (array $item) => $item[3], $keyed);
    }
}
