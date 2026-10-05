<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\AddedLinks;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\AskMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\AskValues;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\BrokenLinks;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\CheckMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\EmptyImages;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\EmptyLinks;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\ExpectedFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\LeftoverTokens;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\LinkMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\MissingAlt;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\PlaceholderImages;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\PlaceholderText;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\SeoLength;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\SeoMissing;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\UnlicensedStock;

/**
 * Finds what is unfinished in an entry, whoever wrote it: every detector
 * over the entry's current values, in form order, with what the session
 * knows added to each gap.
 *
 *     $report = GapFinder::standard()->find(new GapContext(schema: $schema, entry: $entry, richText: $dialect, links: $links, ...));
 *     $report->count();      // the pill
 *     $report->toArray();    // for the guide
 *
 * Nothing it does calls a model: detectors that would (usesModel()) run
 * only with `$withModel`, which only a button that says so may pass. A
 * field that is merely empty isn't reported again where a more specific
 * gap (a fact asked for, an image to choose) already names it.
 *
 * A plain field the CMS requires (text, a date, a select) is not a gap
 * when it is empty: the CMS's own validation says so on save. Ghostwriter's
 * own markers always are, and so is an image the page looks like it needs
 * (EmptyImages).
 */
final class GapFinder
{
    /** @var list<Detector> */
    private readonly array $detectors;

    /**
     * @param  array<int, Detector>  $detectors
     */
    public function __construct(array $detectors)
    {
        $this->detectors = array_values($detectors);
    }

    /** Every deterministic detector core has. */
    public static function standard(): self
    {
        return new self([
            new AskMarkers,
            new AskValues,
            new CheckMarkers,
            new LinkMarkers,
            new EmptyLinks,
            new BrokenLinks,
            new PlaceholderImages,
            new EmptyImages,
            new UnlicensedStock,
            new ExpectedFields,
            new LeftoverTokens,
            new PlaceholderText,
            new MissingAlt,
            new SeoLength,
            new SeoMissing,
            new AddedLinks,
        ]);
    }

    /**
     * The same finder with more detectors (an addon's own).
     */
    public function with(Detector ...$detectors): self
    {
        return new self([...$this->detectors, ...array_values($detectors)]);
    }

    /**
     * @return list<Detector>
     */
    public function detectors(): array
    {
        return $this->detectors;
    }

    public function find(GapContext $context, bool $withModel = false): GapReport
    {
        $gaps = [];

        foreach ($this->detectors as $detector) {
            if ($detector->usesModel() && ! $withModel) {
                continue;
            }

            foreach ($detector->detect($context) as $gap) {
                $gaps[$gap->id] ??= $context->session->enrich($gap);
            }
        }

        // Where a field has a specific gap, "it's empty" adds nothing.
        $specific = [];

        foreach ($gaps as $gap) {
            if (! $gap->kind->isGeneral()) {
                $specific[$gap->path->toString()] = true;
            }
        }

        $gaps = array_filter($gaps, fn (Gap $gap) => ! $gap->kind->isGeneral() || ! isset($specific[$gap->path->toString()]));

        return new GapReport($this->inFormOrder(array_values($gaps), $context));
    }

    /**
     * @param  list<Gap>  $gaps
     * @return list<Gap>
     */
    private function inFormOrder(array $gaps, GapContext $context): array
    {
        $order = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            $order[$visit->path->toString()] ??= count($order);
        }

        $position = fn (Gap $gap) => $order[$gap->path->toString()] ?? PHP_INT_MAX;

        usort($gaps, fn (Gap $a, Gap $b) => [$position($a), $a->occurrence] <=> [$position($b), $b->occurrence]);

        return $gaps;
    }
}
