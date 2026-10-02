<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Finds one or more kinds of gap in an entry. Every detector core ships is
 * deterministic and costs nothing; one that asks a model says so
 * (usesModel()), and GapFinder runs it only when asked.
 */
interface Detector
{
    /**
     * @return list<GapKind>
     */
    public function kinds(): array;

    public function usesModel(): bool;

    /**
     * @return iterable<Gap>
     */
    public function detect(GapContext $context): iterable;
}
