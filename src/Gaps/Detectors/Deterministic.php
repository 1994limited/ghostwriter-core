<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use Generator;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * What the detectors core ships share: none asks a model.
 *
 * @internal
 */
trait Deterministic
{
    public function usesModel(): bool
    {
        return false;
    }

    /**
     * Every field holding text, with its text.
     *
     * @return Generator<int, array{Visit, string}>
     */
    private static function texts(GapContext $context): Generator
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            $text = Walk::text($visit, $context->richText);

            if ($text !== null && trim($text) !== '') {
                yield [$visit, $text];
            }
        }
    }

    /**
     * Whether an empty field should have been filled: the CMS requires it,
     * most entries like this fill it (the pattern's fill rate), or the
     * draft left it for a person.
     */
    private static function expected(GapContext $context, Visit $visit): bool
    {
        return $visit->field->required
            || $context->fillRate($visit->rateKey) >= Placeholders::EXPECTED
            || $context->session->expects($visit->path, $visit->label);
    }

    private static function isProse(Visit $visit): bool
    {
        return in_array($visit->field->kind, [Kind::Text, Kind::LongText, Kind::RichText], true);
    }

    /**
     * "Link to Contact" for each entry whose title or slug matches the
     * hint, best first, then the CMS's own picker.
     *
     * @return array{list<Fix>, list<array{value: mixed, title: string, url: string|null}>}
     */
    private static function linkFixes(GapContext $context, ?string $hint, bool $inline): array
    {
        $candidates = $context->targets !== null && $hint !== null && $hint !== '' ? $context->targets->search(str_replace('-', ' ', $hint), 3) : [];
        $fixes = [];

        foreach ($candidates as $i => $candidate) {
            $fixes[] = Fix::of(FixAction::Link, $i === 0, $candidate->value, params: ['title' => $candidate->title, 'url' => $candidate->url]);
        }

        if ($context->targets === null) {
            // No entries to choose from (Filament): the editor types an address.
            $fixes[] = new Fix(FixAction::Link, new Message('gaps.fix.add-link'), primary: true);
        } else {
            $fixes[] = Fix::of(FixAction::ChooseEntry, $candidates === []);
        }

        if ($inline) {
            $fixes[] = Fix::of(FixAction::RemoveLink);
        }

        return [$fixes, array_map(fn (LinkTarget $target) => $target->toArray(), $candidates)];
    }
}
