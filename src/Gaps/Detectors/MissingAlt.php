<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Images with no alt text, in image fields and inline in rich text, where
 * the asset's container or volume has an alt field (AssetAlt). Only with
 * the addon's AssetRefs and AssetAlt; the striped placeholder is Finish's
 * own gap, so it's left out. A suggestion: never counted, never blocking.
 */
final class MissingAlt implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::MissingAlt];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->assets === null || $context->alt === null) {
            return;
        }

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ((! $visit->field->files && $visit->field->kind !== Kind::RichText) || $visit->isEmpty()) {
                continue;
            }

            $seen = [];

            foreach ($context->assets->in($visit->value, $visit->field) as $asset) {
                if (isset($seen[$asset->key()]) || $context->placeholders?->isPlaceholder($asset, $visit->field) === true) {
                    continue;
                }

                $seen[$asset->key()] = true;

                if ($context->alt->altFor($asset) !== '') {
                    continue;
                }

                yield Gap::make(GapKind::MissingAlt, $visit->path, $visit->label, $asset->key(), fixes: [
                    Fix::of(FixAction::WriteForMe, true, cost: FixCost::Model),
                    Fix::of(FixAction::Focus),
                ], meta: ['asset' => $asset->toArray(), 'filename' => $asset->filename(), 'inline' => $visit->field->kind === Kind::RichText]);
            }
        }
    }
}
