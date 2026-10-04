<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;

/**
 * "Check 3 links Ghostwriter added" (SEO layer §12, `links-added`): each
 * link to another page of the site that the SEO pass added to the draft
 * (the session's Seo\SeoState links, through SessionGaps), while the form
 * still has it. One step a link, so the guide goes through them one by
 * one: where it goes, then **Keep it** (dismissed) or **Remove the link**
 * (the words stay). A link the editor has removed or pointed elsewhere
 * since is no longer found, and its step goes.
 *
 * A suggestion: counted with the suggestions, never in the pill, never
 * blocking (SEO never blocks publishing).
 */
final class AddedLinks implements Detector
{
    use Deterministic;

    /** A markdown link: words, href. */
    private const LINK = '/(?<!!)\[([^\[\]\n]*)\]\(\s*<?([^()\s>]*)>?(?:\s+"[^"\n]*")?\s*\)/u';

    public function kinds(): array
    {
        return [GapKind::LinksAdded];
    }

    public function detect(GapContext $context): iterable
    {
        $added = [];

        foreach ($context->session->links as $link) {
            $added[LinkCandidates::linkKey($link['href']) ?? $link['href']] = $link;
        }

        if ($added === []) {
            return;
        }

        $found = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (! in_array($visit->field->kind, [Kind::RichText, Kind::LongText], true)) {
                continue;
            }

            $text = Walk::text($visit, $context->richText);

            if ($text === null || ! str_contains($text, '](') || preg_match_all(self::LINK, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $seen = [];

            foreach ($matches as $match) {
                $key = LinkCandidates::linkKey($match[2][0]) ?? $match[2][0];
                $link = $added[$key] ?? null;

                if ($link === null || isset($found[$key])) {
                    continue;
                }

                $words = $match[1][0];
                $occurrence = $seen[mb_strtolower($words)] = isset($seen[mb_strtolower($words)]) ? $seen[mb_strtolower($words)] + 1 : 0;
                $found[$key] = [$visit, $link, $words, $match[0][0], $match[0][1], $occurrence, $match[2][0]];
            }
        }

        $count = count($found);

        foreach ($found as [$visit, $link, $words, $markdown, $offset, $occurrence, $href]) {
            yield Gap::make(GapKind::LinksAdded, $visit->path, $visit->label, $words, Markers::excerpt((string) Walk::text($visit, $context->richText), $offset, strlen($markdown)), $occurrence, [
                new Fix(FixAction::Dismiss, new Message('gaps.fix.keep-link'), primary: true),
                Fix::of(FixAction::RemoveLink),
            ], [
                'message' => $count === 1 ? 'gaps.links-added-one' : 'gaps.links-added',
                'count' => $count,
                'words' => $words,
                'href' => $link['href'],
                'formHref' => $href,
                'match' => $markdown,
                'title' => $link['title'],
                'type' => $link['type'],
                'url' => $link['url'] ?? $link['type'],
                'why' => $link['why'],
                'inline' => true,
            ]);
        }
    }
}
