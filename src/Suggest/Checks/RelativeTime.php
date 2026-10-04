<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * Time words that age with the page ("this year", "next spring",
 * "currently", "coming soon"), in an entry last saved at least
 * MONTHS months ago: "this year" then meant the year it was written.
 */
final class RelativeTime implements Check
{
    use ReadsText;

    public const KIND = 'relative-time';

    public const MONTHS = 12;

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $phrases = $context->phrases();

        if ($phrases === null || $phrases->relative === [] || $context->updatedAt === null || ! $context->isOlderThan(self::MONTHS)) {
            return;
        }

        $pattern = self::alternation($phrases->relative);

        foreach ($context->texts() as $text) {
            foreach ($text->matches($pattern) as $match) {
                $anchor = $text->anchor($match['offset'], mb_strlen($match['text']));

                yield Finding::make(Category::OutOfDate, self::KIND, $anchor, Needs::Words, new Message('suggest.finding.relative-time', [
                    'quote' => $anchor->quote?->exact,
                    'year' => (int) $context->updatedAt->format('Y'),
                ]), ['written' => $context->updatedAt->format('Y-m-d'), 'phrase' => mb_strtolower($match['text'])]);
            }
        }
    }
}
