<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * Links whose words say nothing out of context ("click here", "read
 * more", "hier", "en savoir plus"): a screen reader's list of links reads
 * them alone. Inline links in text only; a `#gw-link:` link is Finish's.
 */
final class EmptyLinkText implements Check
{
    public const KIND = 'empty-link-text';

    private const LINK = '/\[([^\[\]\n]+)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"\n]*")?\s*\)/u';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $phrases = $context->phrases();

        if ($phrases === null || $phrases->linkText === []) {
            return;
        }

        $empty = array_map(fn (string $words) => implode(' ', NormalisedText::words($words)), $phrases->linkText);

        foreach ($context->texts() as $text) {
            if (preg_match_all(self::LINK, $text->markdown, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($links as $link) {
                if (Markers::isLinkSentinel($link[2][0]) || ! in_array(implode(' ', NormalisedText::words($link[1][0])), $empty, true)) {
                    continue;
                }

                $at = $text->plainOffset(mb_strlen(substr($text->markdown, 0, $link[1][1])));
                $words = NormalisedText::string($link[1][0], true);

                if ($at === null || $words === '' || mb_substr($text->plain, $at, mb_strlen($words)) !== $words) {
                    continue;
                }

                $anchor = $text->anchor($at, mb_strlen($words));

                yield Finding::make(Category::Accessibility, self::KIND, $anchor, Needs::Words, new Message('suggest.finding.empty-link-text', [
                    'quote' => $words,
                ]), ['href' => $link[2][0]]);
            }
        }
    }
}
