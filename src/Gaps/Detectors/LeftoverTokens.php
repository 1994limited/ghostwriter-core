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
 * A vocabulary placeholder (`[[item]]`) that slipped into the content,
 * through a prompt override with a typo.
 */
final class LeftoverTokens implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::LeftoverToken];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (self::texts($context) as [$visit, $text]) {
            foreach (Markers::leftovers($text) as $token) {
                yield Gap::make(GapKind::LeftoverToken, $visit->path, $visit->label, $token['match'], Markers::excerpt($text, $token['offset'], strlen($token['match'])), $token['occurrence'], [
                    Fix::of(FixAction::Remove, true),
                    Fix::of(FixAction::Focus),
                ], ['match' => $token['match']]);
            }
        }
    }
}
