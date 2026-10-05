<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Links;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * "Link to your other pages" (SEO layer §12, `few-links`): a page of
 * MIN_WORDS words or more, in its rich and long text, with no link to the
 * site's own pages: a CMS reference, a path, or a full address on one of
 * the site's own hosts (GapContext::$hosts). Links to other sites, `mailto:`
 * and the writer's `#gw-link:` markers don't count. One step, on the field
 * with the most words: **Suggest links** (one `seo-editor` and one
 * `seo-verifier` call, Seo\PageLinks, run by the addon) · **Add a link**
 * (focus) · **Skip**. Once Suggest links has run (GapContext::$proposals),
 * the links it found are the steps (ProposedLinks) and this one isn't
 * shown; when it found none, this step says so ("No pages close enough to
 * link to") and offers Add a link · Skip. Suggest edits proposes links
 * too (Suggest\Findings: the reviewer is shown the pages it could link
 * to).
 *
 * Only on a page someone has worked on (GapContext::engaged()). A
 * suggestion: never counted, never blocking.
 */
final class FewLinks implements Detector
{
    use Deterministic;

    /** The fewest words a page has before it should link somewhere. */
    public const MIN_WORDS = 300;

    public function kinds(): array
    {
        return [GapKind::FewLinks];
    }

    public function detect(GapContext $context): iterable
    {
        if (! $context->engaged()) {
            return;
        }

        [$words, $body] = self::words($context);

        if ($words < self::MIN_WORDS || $body === null || Links::of($context, $context->hosts)['internal'] !== []) {
            return;
        }

        // Suggest links has found links still to make: they are the steps.
        if ($context->proposals !== null && ProposedLinks::open($context) !== []) {
            return;
        }

        // It found nothing: said plainly, and the editor adds one by hand.
        if ($context->proposals !== null && $context->proposals->isEmpty()) {
            yield Gap::make(GapKind::FewLinks, $body->path, $body->label, fixes: [
                new Fix(FixAction::Focus, new Message('gaps.fix.add-links'), primary: true),
                new Fix(FixAction::Dismiss, new Message('gaps.fix.skip')),
            ], meta: [
                'message' => 'gaps.few-links.none',
                'words' => $words,
                'step' => 'gaps.step.few-links',
                'none' => $context->proposals->none,
            ]);

            return;
        }

        yield Gap::make(GapKind::FewLinks, $body->path, $body->label, fixes: [
            new Fix(FixAction::SuggestLinks, new Message('gaps.fix.suggest-links'), cost: FixCost::Model, primary: true),
            new Fix(FixAction::Focus, new Message('gaps.fix.add-links')),
            new Fix(FixAction::Dismiss, new Message('gaps.fix.skip')),
        ], meta: [
            'words' => $words,
            'step' => 'gaps.step.few-links',
            'running' => 'gaps.fix.suggesting-links',
        ]);
    }

    /**
     * The words in the page's rich and long text, and the field with the
     * most of them (where the step points).
     *
     * @return array{0: int, 1: ?Visit}
     */
    public static function words(GapContext $context): array
    {
        $total = 0;
        $most = 0;
        $body = null;

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (! in_array($visit->field->kind, [Kind::RichText, Kind::LongText], true)) {
                continue;
            }

            $text = Walk::text($visit, $context->richText);
            $count = $text === null ? 0 : (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', (string) preg_replace('/\]\([^)]*\)/u', ']', $text));
            $total += $count;

            if ($count > $most) {
                $most = $count;
                $body = $visit;
            }
        }

        return [$total, $body];
    }
}
