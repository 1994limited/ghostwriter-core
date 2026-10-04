<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;

/**
 * An image field left empty that the page looks like it needs: a prompt
 * (Severity::Prompt), counted in the header and the menu and bringing the
 * guide out, but never blocking: where the field is required, the CMS's own
 * validation says so on save.
 *
 * It looks needed, and the step says why (`meta.why`), when:
 *
 * - `required`: the CMS requires it;
 * - `prominent`: it is the page's prominent image: in the block type the
 *   template prints the page's `h1` from (the render profile), or, where
 *   the group's published entries were looked at and are too few to go by,
 *   a top-level image whose name says so ("Hero image", "Banner", "Cover
 *   photo"). Without a pattern at all (a context that didn't look, such
 *   as Suggest edits' checks), a name alone is nothing;
 * - `siblings`: at least SHARE of the group's newest published entries
 *   (FillRates, at least SIBLINGS_KNOWN of them) fill it;
 * - `draft`: the draft left it for a person.
 *
 * An optional image that few entries like this use is no gap. Nor is any
 * of these on a new, untouched entry (GapContext::engaged()): it prompts
 * once a draft is applied or the entry has words in it.
 */
final class EmptyImages implements Detector
{
    use Deterministic;

    /** The share of published siblings that must fill an image for it to look needed. */
    public const SHARE = 0.7;

    /** The fewest published siblings whose fill rates are evidence; with fewer, a hero-like name is. */
    public const SIBLINGS_KNOWN = 3;

    /** Words in a top-level image field's handle or label that make it the page's prominent image on their own. */
    private const HERO_WORDS = ['hero', 'banner', 'cover', 'masthead', 'splash'];

    /** Words that do with an image word beside them ("Featured image", "Main photo", "Header picture"). */
    private const LEAD_WORDS = ['featured', 'feature', 'main', 'lead', 'header', 'key', 'primary', 'top'];

    private const IMAGE_WORDS = ['image', 'img', 'photo', 'picture', 'pic', 'visual', 'media'];

    public function kinds(): array
    {
        return [GapKind::ImageEmpty];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (! Placeholders::takesImages($visit->field) || ! $visit->isEmpty()) {
                continue;
            }

            $why = self::why($context, $visit);

            if ($why === null || ! $context->engaged()) {
                continue;
            }

            $meta = ['why' => $why, 'message' => self::messageKey($context, $why)];

            if ($context->group !== '') {
                $meta['group'] = $context->group;
            }

            if ($context->pattern !== null && $visit->rateKey !== '') {
                $meta['filled'] = $context->fillRate($visit->rateKey);
            }

            yield Gap::make(GapKind::ImageEmpty, $visit->path, $visit->label, fixes: [Fix::of(FixAction::FindPhoto, true), Fix::of(FixAction::ChooseAsset)], meta: $meta);
        }
    }

    /**
     * Why an empty image looks needed (`required`, `prominent`, `siblings`,
     * `draft`), or null when it doesn't.
     */
    public static function why(GapContext $context, Visit $visit): ?string
    {
        $known = self::siblingsKnown($context, $visit);

        return match (true) {
            $visit->field->required => 'required',
            self::rendersAtTop($context, $visit) => 'prominent',
            $known && $context->fillRate($visit->rateKey) >= self::SHARE => 'siblings',
            $context->pattern !== null && ! $known && self::namedHero($visit) => 'prominent',
            $context->session->expects($visit->path, $visit->label) => 'draft',
            default => null,
        };
    }

    /**
     * Whether the group's fill rates say anything about this place: rates
     * counted over at least SIBLINGS_KNOWN entries (or over an unknown
     * number, from a pattern that doesn't say) that include it.
     */
    private static function siblingsKnown(GapContext $context, Visit $visit): bool
    {
        if ($context->pattern === null || $visit->rateKey === '' || ! array_key_exists($visit->rateKey, $context->pattern->filled)) {
            return false;
        }

        $siblings = $context->siblings();

        return $siblings === 0 || $siblings >= self::SIBLINGS_KNOWN;
    }

    /**
     * An image in the block type the template prints the page's `h1` from
     * (`hero.heading`): that block is the top of the page.
     */
    private static function rendersAtTop(GapContext $context, Visit $visit): bool
    {
        $h1 = $context->profile?->rendered() ? $context->profile->h1Field : null;

        if ($h1 === null || ! str_contains($h1, '.') || ! str_contains($visit->rateKey, '.')) {
            return false;
        }

        return explode('.', $h1, 2)[0] === explode('.', $visit->rateKey, 2)[0];
    }

    /** A top-level image whose name says it is the page's: "Hero image", "bannerImage", "Featured photo". */
    private static function namedHero(Visit $visit): bool
    {
        if (count($visit->path->segments) !== 1) {
            return false;
        }

        $words = self::words($visit->field->handle.' '.$visit->field->label);

        if (array_intersect($words, self::HERO_WORDS) !== []) {
            return true;
        }

        return array_intersect($words, self::LEAD_WORDS) !== [] && array_intersect($words, self::IMAGE_WORDS) !== [];
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $text = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $text);
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    /** The step's message: why it looks needed, in the guide's words. */
    private static function messageKey(GapContext $context, string $why): string
    {
        return match ($why) {
            'required' => 'gaps.image-empty.required',
            'prominent' => 'gaps.image-empty.prominent',
            'siblings' => $context->group !== '' ? 'gaps.image-empty.siblings' : 'gaps.image-empty',
            default => 'gaps.image-empty',
        };
    }
}
