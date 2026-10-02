<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;

/**
 * A fact the writer asked for in a field that can't hold text (a price in
 * a number field, a date), while that field is still empty. Only the
 * session's gap list knows these: the field itself holds nothing to mark.
 * It blocks when the field is required, and is counted otherwise.
 */
final class AskValues implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::AskValue];
    }

    public function detect(GapContext $context): iterable
    {
        $asks = $context->session->askValues();

        if ($asks === []) {
            return;
        }

        $visits = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            $visits[$visit->path->toString()] = $visit;
        }

        $seen = [];

        foreach ($asks as $ask) {
            $visit = $visits[$ask['path']] ?? null;

            if ($visit === null || ! $visit->isEmpty()) {
                continue;
            }

            $occurrence = $seen[$ask['path']] = isset($seen[$ask['path']]) ? $seen[$ask['path']] + 1 : 0;

            yield Gap::make(GapKind::AskValue, $visit->path, $visit->label, $ask['hint'], null, $occurrence, [Fix::of(FixAction::Focus, true)], severity: $visit->field->required ? Severity::Blocks : Severity::Required);
        }
    }
}
