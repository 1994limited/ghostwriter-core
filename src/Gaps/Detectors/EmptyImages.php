<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;

/**
 * An image field left empty that should have an image: the same rule as
 * the placeholders (required, or filled on at least half the entries like
 * this), or left for a person by the draft.
 */
final class EmptyImages implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::ImageEmpty];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (Placeholders::takesImages($visit->field) && $visit->isEmpty() && self::expected($context, $visit)) {
                yield Gap::make(GapKind::ImageEmpty, $visit->path, $visit->label, fixes: [Fix::of(FixAction::FindPhoto, true), Fix::of(FixAction::ChooseAsset)]);
            }
        }
    }
}
