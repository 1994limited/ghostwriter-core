<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Stock photos previewed but not licensed, wherever the entry holds them:
 * one question to the ledger (StockImages::unlicensedAmong()) for every
 * asset in the entry. The stock feature owns licensing; the fix only opens
 * its License & replace confirm.
 */
final class UnlicensedStock implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::StockPreview];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->stock === null || $context->assets === null) {
            return;
        }

        /** @var list<array{Visit, AssetRef}> $found */
        $found = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (($visit->field->files || $visit->field->kind === Kind::RichText) && ! $visit->isEmpty()) {
                foreach ($context->assets->in($visit->value, $visit->field) as $asset) {
                    $found[] = [$visit, $asset];
                }
            }
        }

        if ($found === []) {
            return;
        }

        $unlicensed = [];

        foreach ($context->stock->unlicensedAmong(array_map(fn (array $pair) => $pair[1], $found)) as $image) {
            $unlicensed[$image->asset->key()] = $image;
        }

        $seen = [];

        foreach ($found as [$visit, $asset]) {
            $image = $unlicensed[$asset->key()] ?? null;

            if ($image === null) {
                continue;
            }

            $where = $visit->path->toString();
            $occurrence = $seen[$where] = isset($seen[$where]) ? $seen[$where] + 1 : 0;

            yield Gap::make(GapKind::StockPreview, $visit->path, $visit->label, $image->library, null, $occurrence, [
                Fix::of(FixAction::License, true, $image->id, FixCost::Licence),
                Fix::of(FixAction::ChooseAnother),
            ], [
                'stockId' => $image->id,
                'library' => $image->library,
                'externalId' => $image->externalId,
                'state' => $image->state(),
                'expired' => $image->is(StockImage::PREVIEW) && $image->comp() === null && $image->compDownloads() !== [],
                'mayRefresh' => $image->mayRefreshComp(),
                'inline' => $visit->field->kind === Kind::RichText,
                'asset' => $asset->toArray(),
            ]);
        }
    }
}
