<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapReport;

/**
 * What the free checks found in one entry: the findings (suggestions in
 * waiting), and Finish this page's gaps, which aren't suggestions but
 * count in the revisit list (leftover markers, expected fields left empty).
 */
final class FindingReport
{
    /**
     * @param  list<Finding>  $findings  In form order, quieted ones left out.
     */
    public function __construct(
        public readonly array $findings,
        public readonly GapReport $gaps,
    ) {}

    /**
     * @return list<Finding>
     */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->findings, fn (Finding $finding) => $finding->kind === $kind));
    }

    /** Gaps an editor left unfinished: markers, placeholders and stray tokens. */
    public function leftovers(): int
    {
        return count(array_filter($this->gaps->all(), fn ($gap) => in_array($gap->kind, [
            GapKind::Ask, GapKind::AskValue, GapKind::Check, GapKind::LinkToChoose, GapKind::ImagePlaceholder,
            GapKind::StockPreview, GapKind::LeftoverToken, GapKind::PlaceholderText,
        ], true)));
    }

    /**
     * Fields left empty that the CMS requires or most pages like this
     * fill, but those at $except (paths: an SEO description, which is its
     * own reason).
     *
     * @param  array<int, string>  $except
     */
    public function emptyFields(array $except = []): int
    {
        return count(array_filter($this->gaps->all(), fn ($gap) => in_array($gap->kind, [GapKind::Required, GapKind::Expected, GapKind::ImageEmpty, GapKind::LinkEmpty], true) && ! in_array($gap->path->toString(), $except, true)));
    }
}
