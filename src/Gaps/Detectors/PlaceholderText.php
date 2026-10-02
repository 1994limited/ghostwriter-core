<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * Text that looks like it is waiting for something: `TBC`, `[insert
 * date]`, `lorem ipsum`. A suggestion: "It's fine" dismisses it.
 */
final class PlaceholderText implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::PlaceholderText];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (self::texts($context) as [$visit, $text]) {
            foreach (Markers::placeholderText($text) as $found) {
                yield Gap::make(GapKind::PlaceholderText, $visit->path, $visit->label, $found['match'], Markers::excerpt($text, $found['offset'], strlen($found['match'])), $found['occurrence'], [
                    Fix::of(FixAction::Focus, true),
                    Fix::of(FixAction::Dismiss),
                ], ['match' => $found['match']]);
            }
        }
    }
}
