<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * What one Apply did, by thread number: the threads it changed, those it
 * only replied to, those it refused (with the validator's rules) or
 * skipped because someone changed their text during the run, and those
 * whose block was laid out anew. `summary` is the line it added to the
 * chat; `failed` says why the run couldn't happen at all.
 */
final class ApplyOutcome
{
    /**
     * @param  list<int>  $changed
     * @param  list<int>  $replied
     * @param  array<int, list<string>>  $refused  Number => RevisionValidator rules.
     * @param  list<int>  $conflicted
     * @param  list<int>  $laidOut
     * @param  list<string>  $units  The units and extra items changed.
     */
    public function __construct(
        public readonly array $changed = [],
        public readonly array $replied = [],
        public readonly array $refused = [],
        public readonly array $conflicted = [],
        public readonly array $laidOut = [],
        public readonly array $units = [],
        public readonly string $summary = '',
        public readonly ?string $failed = null,
        public readonly Usage $usage = new Usage,
    ) {}

    /** Whether the draft changed. */
    public function changedDraft(): bool
    {
        return $this->changed !== [];
    }
}
