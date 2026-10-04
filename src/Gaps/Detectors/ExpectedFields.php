<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Prose fields left empty that most entries like this fill (a summary, an
 * SEO description): a suggestion, never counted and never blocking. Only
 * where the group's pattern was learned, and never for a required field:
 * the CMS's own validation says so on save.
 */
final class ExpectedFields implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::Expected];
    }

    public function detect(GapContext $context): iterable
    {
        if ($context->pattern === null) {
            return;
        }

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (! $visit->field->required && self::isProse($visit) && $context->fillRate($visit->rateKey) >= Placeholders::EXPECTED && self::blank($context, $visit)) {
                yield Gap::make(GapKind::Expected, $visit->path, $visit->label, fixes: self::writeFixes($visit), meta: ['filled' => $context->fillRate($visit->rateKey)]);
            }
        }
    }

    /**
     * Empty, as an editor sees it: rich text with no words counts too.
     */
    public static function blank(GapContext $context, Visit $visit): bool
    {
        if ($visit->field->kind === Kind::RichText) {
            return ! $context->richText->isWritten($visit->value) || trim((string) Walk::text($visit, $context->richText)) === '';
        }

        if ($visit->field->kind === Kind::Toggle || $visit->field->kind === Kind::Number) {
            return $visit->value === null || $visit->value === '';
        }

        return $visit->isEmpty();
    }

    /**
     * "Write it for me" (one small model call, from the page's own text)
     * for prose; "I'll write it" always.
     *
     * @return list<Fix>
     */
    public static function writeFixes(Visit $visit): array
    {
        $prose = in_array($visit->field->kind, [Kind::Text, Kind::LongText, Kind::RichText], true);

        return $prose
            ? [Fix::of(FixAction::WriteForMe, true, cost: FixCost::Model), Fix::of(FixAction::Focus)]
            : [Fix::of(FixAction::Focus, true)];
    }
}
