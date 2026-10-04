<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * A year before this one written as if it were current: "New for 2024",
 * "our 2024 prices", "as of 2023". Only in a phrase that reads as current
 * (the language's `current` list); a year written as history ("since
 * 2015", "founded in 2009", "in 2019 we won") is never flagged, and nor is
 * a year on its own ("our 2023 show garden").
 *
 * In a dated group (AgePolicy), a year the entry was written in or after
 * is history too: a 2023 post may say "new for 2023".
 */
final class PastYears implements Check
{
    use ReadsText;

    public const KIND = 'past-year';

    private const YEAR = '(?:19[89]\d|20\d\d)';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $phrases = $context->phrases();

        if ($phrases === null || $phrases->current === []) {
            return;
        }

        $thisYear = (int) $context->now->format('Y');
        $written = $context->updatedAt !== null ? (int) $context->updatedAt->format('Y') : null;
        $pattern = self::alternation($phrases->current, ['{year}' => self::YEAR]);

        foreach ($context->texts() as $text) {
            $taken = [];

            foreach ($text->matches($pattern) as $match) {
                $year = preg_match('/(?<!\d)'.self::YEAR.'(?!\d)/', $match['text'], $y) === 1 ? (int) $y[0] : 0;

                if ($year === 0 || $year >= $thisYear || self::overlaps($taken, $match['offset'], mb_strlen($match['text']))) {
                    continue;
                }

                if ($context->dated() && $written !== null && $year >= $written) {
                    continue;
                }

                if (self::isHistory($text->plain, $match['offset'], mb_strlen($match['text']), $phrases->history, ['{year}' => (string) $year])) {
                    continue;
                }

                $taken[] = [$match['offset'], mb_strlen($match['text'])];
                $anchor = $text->anchor($match['offset'], mb_strlen($match['text']));

                yield Finding::make(Category::OutOfDate, self::KIND, $anchor, Needs::Words, new Message('suggest.finding.past-year', [
                    'quote' => $anchor->quote?->exact,
                    'year' => $year,
                    'now' => $thisYear,
                ]), ['year' => $year]);
            }
        }
    }
}
