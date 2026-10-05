<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Seo\MetaRange;

/**
 * "Add a description for search" (SEO layer §12, `seo-missing`): the
 * page's SEO description is empty, or shorter than search results have
 * room for (MetaRange: under 120 characters for a 160 limit), wherever
 * the addon's SeoFields finds it, including one inherited from an empty
 * field (decision 11). One over its limit is SeoLength's.
 *
 * Where the draft that was applied has a description (SessionGaps::$meta),
 * the step offers it: **Use this** (free: the text is written already) ·
 * **I'll write it**. Otherwise only the second.
 *
 * Only on a page someone has worked on (GapContext::engaged()); never on a
 * template's text or a switched-off value. A suggestion: never counted,
 * never blocking.
 */
final class SeoMissing implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::SeoMissing];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->seo === null || ! $context->engaged()) {
            return;
        }

        foreach ($context->seo->in($context->schema, $context->entry) as $field) {
            if ($field->role !== SeoField::DESCRIPTION || ! $field->checkable() || $field->tooLong()) {
                continue;
            }

            // An inherited description only counts when the page could have its own.
            if ($field->inherited() && ! $field->writable) {
                continue;
            }

            $range = MetaRange::of($field);
            $empty = $field->isEmpty();

            if (! $empty && ! $range->tooShort($field->text)) {
                continue;
            }

            $draft = trim($context->session->meta[SeoField::DESCRIPTION] ?? '');
            $draft = $draft !== '' && $draft !== trim((string) $field->text) && $range->length($draft) <= $field->limit ? $draft : '';
            $message = match (true) {
                $empty && $field->source === SeoSource::Field => 'gaps.seo-missing.inherited',
                $empty => 'gaps.seo-missing',
                default => 'gaps.seo-missing.short',
            };

            yield Gap::make(GapKind::SeoMissing, $field->path, $field->label, $field->role, fixes: array_values(array_filter([
                $draft !== '' ? Fix::of(FixAction::UseText, true, $draft) : null,
                Fix::of(FixAction::Focus, $draft === ''),
            ])), meta: [
                'message' => $message.($draft !== '' ? ($message === 'gaps.seo-missing' ? '.draft' : '-draft') : ''),
                'role' => $field->role,
                'limit' => $field->limit,
                'length' => $field->length(),
                'min' => $range->min,
                'max' => $range->max,
                'text' => $draft !== '' ? $draft : null,
                'field' => $field->inheritsFrom,
                'writable' => $field->writable,
                'inheritsFrom' => $field->inheritsFrom,
                'source' => $field->source->value,
                'step' => 'gaps.step.seo-missing',
            ]);
        }
    }
}
