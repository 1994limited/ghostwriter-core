<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Ghostwriter's striped placeholder, in an image field or inline in rich
 * text, recognised by its asset through the addon's PlaceholderAssets.
 */
final class PlaceholderImages implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::ImagePlaceholder];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->placeholders === null || $context->assets === null) {
            return;
        }

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            $inline = $visit->field->kind === Kind::RichText;

            if ((! $visit->field->files && ! $inline) || $visit->isEmpty()) {
                continue;
            }

            $occurrence = 0;

            foreach ($context->assets->in($visit->value, $visit->field) as $asset) {
                if (! $context->placeholders->isPlaceholder($asset, $visit->field)) {
                    continue;
                }

                $fixes = [Fix::of(FixAction::FindPhoto, true), Fix::of(FixAction::ChooseAsset)];

                if (! $visit->field->required && ! $inline) {
                    $fixes[] = Fix::of(FixAction::LeaveEmpty);
                }

                yield Gap::make(GapKind::ImagePlaceholder, $visit->path, $visit->label, null, null, $occurrence++, $fixes, ['inline' => $inline, 'asset' => $asset->toArray()]);
            }
        }
    }
}
