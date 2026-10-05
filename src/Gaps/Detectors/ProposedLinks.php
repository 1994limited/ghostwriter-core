<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkCandidates;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposal;

/**
 * "Link “winter care” to Winter care visits?" (SEO layer §12,
 * `link-proposed`): each link Finish's **Suggest links** found for the page
 * (GapContext::$proposals, Seo\PageLinks), one step a link, while it is
 * still one to make: its words are still in their field, not linked, and
 * the page doesn't link to that page already. **Link it** links those words
 * in the form's editor (FixAction::Link, the href as the field stores it);
 * **Skip** leaves them. A linked or edited-away proposal's step goes.
 *
 * `occurrence` is which of the words' unlinked repeats in the field it is
 * (0 for the first), as the front end counts them in the editor.
 *
 * A suggestion: never counted, never blocking.
 */
final class ProposedLinks implements Detector
{
    use Deterministic;

    /** A markdown link or image: its whole text, its href. */
    private const LINK = '/!?\[[^\[\]\n]*\]\(\s*<?([^()\s>]*)>?(?:\s+"[^"\n]*")?\s*\)/u';

    public function kinds(): array
    {
        return [GapKind::LinkProposed];
    }

    public function detect(GapContext $context): iterable
    {
        $open = self::open($context);
        $count = count($open);

        foreach ($open as ['proposal' => $proposal, 'visit' => $visit, 'text' => $text, 'offset' => $offset, 'length' => $length, 'occurrence' => $occurrence]) {
            $bytes = strlen(mb_substr($text, 0, $offset));

            yield Gap::make(GapKind::LinkProposed, $visit->path, $visit->label, $proposal->words, Markers::excerpt($text, $bytes, strlen($proposal->words)), $occurrence, [
                new Fix(FixAction::Link, new Message('gaps.fix.link-it'), $proposal->href, primary: true),
                new Fix(FixAction::Dismiss, new Message('gaps.fix.skip')),
            ], [
                'step' => 'gaps.step.link-proposed',
                'words' => $proposal->words,
                'href' => $proposal->href,
                'title' => $proposal->title,
                'type' => $proposal->type,
                'url' => $proposal->url ?? '',
                'why' => $proposal->why,
                'prefix' => $proposal->quote->prefix,
                'suffix' => $proposal->quote->suffix,
                'proposal' => $proposal->id,
                'count' => $count,
                'inline' => true,
            ]);
        }
    }

    /**
     * The proposals still to make, in the order found, with where their
     * words are: the field's text (Markdown), the offset and length in it
     * (characters), and which unlinked repeat of the words it is.
     *
     * @return list<array{proposal: LinkProposal, visit: Visit, text: string, offset: int, length: int, occurrence: int}>
     */
    public static function open(GapContext $context): array
    {
        $proposals = $context->proposals->links ?? [];

        if ($proposals === [] || ! $context->engaged()) {
            return [];
        }

        $visits = [];
        $linked = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            $text = Walk::text($visit, $context->richText);

            if ($text === null) {
                continue;
            }

            $visits[$visit->path->toString()] = [$visit, $text];

            if (str_contains($text, '](') && preg_match_all(self::LINK, $text, $matches) > 0) {
                foreach ($matches[1] as $href) {
                    $linked[LinkCandidates::linkKey($href) ?? $href] = true;
                }
            }
        }

        $open = [];
        $finder = new QuoteFinder;

        foreach ($proposals as $proposal) {
            [$visit, $text] = $visits[$proposal->path->toString()] ?? [null, null];

            if ($visit === null || $text === null || isset($linked[LinkCandidates::linkKey($proposal->href) ?? $proposal->href])) {
                continue;
            }

            $found = $finder->find($proposal->quote, $text);

            if ($found === null || $found->fuzzy || mb_substr($text, $found->offset, $found->length) !== $proposal->words) {
                continue;
            }

            $links = self::links($text);

            if (self::inside($found->offset, $found->length, $links)) {
                continue;
            }

            $occurrence = 0;
            $from = 0;

            while (($at = mb_strpos($text, $proposal->words, $from)) !== false && $at < $found->offset) {
                if (! self::inside($at, mb_strlen($proposal->words), $links)) {
                    $occurrence++;
                }

                $from = $at + 1;
            }

            $open[] = ['proposal' => $proposal, 'visit' => $visit, 'text' => $text, 'offset' => $found->offset, 'length' => $found->length, 'occurrence' => $occurrence];
        }

        return $open;
    }

    /**
     * The links and images in some Markdown, as [start, end) in characters.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function links(string $text): array
    {
        $ranges = [];

        if (preg_match_all(self::LINK, $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$found, $byte]) {
                $start = mb_strlen(substr($text, 0, $byte));
                $ranges[] = [$start, $start + mb_strlen($found)];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private static function inside(int $offset, int $length, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset < $end && $offset + $length > $start) {
                return true;
            }
        }

        return false;
    }
}
