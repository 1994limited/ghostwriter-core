<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\ClosingDates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\EmptyLinkText;
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
        $gaps = $this->gaps->find($context->gaps);
        $found = [];

        foreach ($this->checks as $check) {
            foreach ($check->find($context) as $finding) {
                $found[] = $finding;
            }
        }

        foreach ($gaps->all() as $gap) {
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

    /** A Finish gap that is also a suggestion, as a finding; null for the rest. */
    private function fromGap(Gap $gap, CheckContext $context): ?Finding
    {
        return match ($gap->kind) {
            GapKind::LinkBroken => $this->brokenLink($gap, $context),
            GapKind::MissingAlt => $this->missingAlt($gap),
            GapKind::SeoLength => $this->seoLength($gap, $context),
            GapKind::Expected => $this->emptySeo($gap, $context),
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
        ]), array_intersect_key($gap->meta, array_flip(['role', 'limit', 'length', 'writable', 'inheritsFrom'])));
    }

    /** An empty SEO description most pages like this fill. */
    private function emptySeo(Gap $gap, CheckContext $context): ?Finding
    {
        $seo = $context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? [];
        $field = null;

        foreach ($seo as $candidate) {
            if ($candidate->path->equals($gap->path) && $candidate->role === SeoField::DESCRIPTION && $candidate->writable) {
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
        ]);
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
