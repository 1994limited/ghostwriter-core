<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;

/**
 * Links to an entry that no longer exists, in link fields and inline. Only
 * with the addon's LinkTargets, which decides what it can check: anything
 * it can't tell about (an outside address) is left alone.
 */
final class BrokenLinks implements Detector
{
    use Deterministic;

    /** An inline link's words and address, in markdown. */
    private const INLINE = '/\[([^\[\]\n]*)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"\n]*")?\s*\)/u';

    public function kinds(): array
    {
        return [GapKind::LinkBroken];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->targets === null) {
            return;
        }

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($context->links->holdsLinks($visit->field)) {
                if ($context->links->hasLink($visit->value) && ! Markers::isLinkSentinel(json_encode($visit->value)) && $context->targets->exists($visit->value, $visit->field) === false) {
                    yield Gap::make(GapKind::LinkBroken, $visit->path, $visit->label, fixes: [Fix::of(FixAction::ChooseEntry, true)], meta: ['inline' => false]);
                }

                continue;
            }

            $text = Walk::text($visit, $context->richText);

            if ($text === null || preg_match_all(self::INLINE, $text, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            $seen = [];

            foreach ($matches as $match) {
                $href = $match[2];

                if (Markers::isLinkSentinel($href) || preg_match('/^(?:#|mailto:|tel:)/i', $href) === 1 || $context->targets->exists($href, $visit->field) !== false) {
                    continue;
                }

                $occurrence = $seen[$href] = isset($seen[$href]) ? $seen[$href] + 1 : 0;

                yield Gap::make(GapKind::LinkBroken, $visit->path, $visit->label, $href, null, $occurrence, [Fix::of(FixAction::ChooseEntry, true), Fix::of(FixAction::RemoveLink)], ['inline' => true, 'words' => $match[1]]);
            }
        }
    }
}
