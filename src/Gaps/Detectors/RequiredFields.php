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
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * Required fields left empty. The CMS's own validation already stops the
 * save, so these are counted in the pill but never repeated by the publish
 * guard. Image and link fields are left to their own detectors.
 */
final class RequiredFields implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::Required];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($visit->field->required && ! $visit->field->files && ! $context->links->holdsLinks($visit->field) && self::blank($context, $visit)) {
                yield Gap::make(GapKind::Required, $visit->path, $visit->label, fixes: self::writeFixes($visit));
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
