<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;

/**
 * An SEO title or description over its limit (SeoField::$limit: the
 * field's own, or 60 and 160), wherever the addon's SeoFields finds it.
 * Values from a template or switched off (no text) aren't checked; an
 * inherited one is (the page prints it), with its source in the meta so
 * the fix gives the page its own. A suggestion: never counted, never
 * blocking.
 */
final class SeoLength implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::SeoLength];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->seo === null) {
            return;
        }

        foreach ($context->seo->in($context->schema, $context->entry) as $field) {
            if (! $field->tooLong()) {
                continue;
            }

            yield Gap::make(GapKind::SeoLength, $field->path, $field->label, $field->role, fixes: [
                Fix::of(FixAction::Shorten, true, cost: FixCost::Model),
                Fix::of(FixAction::Focus),
            ], meta: [
                'role' => $field->role,
                'limit' => $field->limit,
                'length' => $field->length(),
                'writable' => $field->writable,
                'inheritsFrom' => $field->inheritsFrom,
                'source' => $field->source->value,
            ]);
        }
    }
}
