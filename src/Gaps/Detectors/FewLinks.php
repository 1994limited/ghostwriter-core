<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
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
 * with the most words: **Add a link** (focus) · **Skip**. Suggest edits
 * proposes the links themselves (Suggest\Findings: the reviewer is shown
 * the pages it could link to).
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

        yield Gap::make(GapKind::FewLinks, $body->path, $body->label, fixes: [
            new Fix(FixAction::Focus, new Message('gaps.fix.add-links'), primary: true),
            new Fix(FixAction::Dismiss, new Message('gaps.fix.skip')),
        ], meta: [
            'words' => $words,
            'step' => 'gaps.step.few-links',
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
