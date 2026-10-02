<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;

/**
 * A link field with nothing in it that should have something: required,
 * filled on most entries like this, or left for a person by the draft
 * (Statamic's `entries` fields can't be stood in for, so they are found
 * here).
 */
final class EmptyLinks implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::LinkEmpty];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($context->links->holdsLinks($visit->field) && ! $context->links->hasLink($visit->value) && self::expected($context, $visit)) {
                yield Gap::make(GapKind::LinkEmpty, $visit->path, $visit->label, fixes: [Fix::of(FixAction::ChooseEntry, true)]);
            }
        }
    }
}
