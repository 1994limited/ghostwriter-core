<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Anchor;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * Links to other sites that the opt-in weekly check found gone twice in a
 * row (CheckContext::$external). This check makes no request: it reads
 * the stored results. With the check off, there are none.
 */
final class ExternalLinks implements Check
{
    public const KIND = 'external-link';

    private const LINK = '/\[([^\[\]\n]+)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"\n]*")?\s*\)/u';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $broken = array_filter($context->external, fn ($result) => $result->isBroken());

        if ($broken === []) {
            return;
        }

        foreach ($context->texts() as $text) {
            if (preg_match_all(self::LINK, $text->markdown, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($links as $link) {
                $result = $broken[$link[2][0]] ?? null;
                $words = NormalisedText::string($link[1][0], true);
                $at = $result === null ? null : $text->plainOffset(mb_strlen(substr($text->markdown, 0, $link[1][1])));

                if ($result === null || $at === null || $words === '' || mb_substr($text->plain, $at, mb_strlen($words)) !== $words) {
                    continue;
                }

                $anchor = $text->anchor($at, mb_strlen($words));

                yield Finding::make(Category::Link, self::KIND, $anchor, Needs::Nothing, new Message('suggest.finding.external-link', [
                    'quote' => $words,
                    'status' => $result->code ?? $result->error,
                ]), ['href' => $result->url, 'code' => $result->code, 'checkedAt' => $result->checkedAt, 'inline' => true]);
            }
        }

        foreach (Walk::entry($context->gaps->schema, $context->gaps->entry) as $visit) {
            if (! $context->gaps->links->holdsLinks($visit->field)) {
                continue;
            }

            $values = is_array($visit->value) ? $visit->value : [$visit->value];
            array_walk_recursive($values, function ($item) use (&$found, $broken): void {
                if (is_string($item) && isset($broken[$item])) {
                    $found ??= $broken[$item];
                }
            });

            if (isset($found)) {
                $anchor = new Anchor(AnchorScope::Field, $visit->path, $visit->label, fieldHash: Anchor::hash($found->url), passage: Anchor::hash($found->url));

                yield Finding::make(Category::Link, self::KIND, $anchor, Needs::Nothing, new Message('suggest.finding.external-link-field', [
                    'label' => $visit->label,
                    'status' => $found->code ?? $found->error,
                ]), ['href' => $found->url, 'code' => $found->code, 'checkedAt' => $found->checkedAt, 'inline' => false]);
                $found = null;
            }
        }
    }
}
