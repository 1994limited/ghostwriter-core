<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;

/**
 * Prose fields left empty that most entries like this fill (a summary, an
 * SEO description): a suggestion, never counted and never blocking. Only
 * where the group's pattern was learned.
 */
final class ExpectedFields implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::Expected];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->pattern === null) {
            return;
        }

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (! $visit->field->required && self::isProse($visit) && $context->fillRate($visit->rateKey) >= Placeholders::EXPECTED && RequiredFields::blank($context, $visit)) {
                yield Gap::make(GapKind::Expected, $visit->path, $visit->label, fixes: RequiredFields::writeFixes($visit), meta: ['filled' => $context->fillRate($visit->rateKey)]);
            }
        }
    }
}
