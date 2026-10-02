<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * `[[ask: …]]` in any text. The fixes are the editor's own answer, or a
 * rewrite of the sentence without the fact. Never a value a model
 * suggests: facts come only from the editor.
 */
final class AskMarkers implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::Ask];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (self::texts($context) as [$visit, $text]) {
            foreach (Markers::asks($text) as $ask) {
                yield Gap::make(GapKind::Ask, $visit->path, $visit->label, $ask['hint'], Markers::excerpt($text, $ask['offset'], strlen($ask['match'])), $ask['occurrence'], [
                    Fix::of(FixAction::Answer, true),
                    Fix::of(FixAction::WriteAround, cost: FixCost::Model),
                ], ['match' => $ask['match']]);
            }
        }
    }
}
