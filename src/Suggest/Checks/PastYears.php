<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Watches;

/**
 * A year before this one written as if it were current: "New for 2024",
 * "our 2024 prices", "as of 2023". Only in a phrase that reads as current
 * (the language's `current` list); a year written as history ("since
 * 2015", "founded in 2009", "in 2019 we won") is never flagged, and nor is
 * a year on its own ("our 2023 show garden").
 *
 * In a dated group (AgePolicy), a year the entry was written in or after
 * is history too: a 2023 post may say "new for 2023".
 *
 * The finding is anchored on the sentence the phrase is in (a heading is
 * a sentence of its own), one a sentence, so the review call rewrites the
 * sentence as a whole. `meta['phrase']` is the dated words as written and
 * `meta['phraseOffset']` where they start in the quote.
 */
final class PastYears implements Check, Watches
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
            $sentences = [];

            foreach ($text->matches($pattern) as $match) {
                $length = mb_strlen($match['text']);
                $year = preg_match('/(?<!\d)'.self::YEAR.'(?!\d)/', $match['text'], $y) === 1 ? (int) $y[0] : 0;

                if ($year === 0 || $year >= $thisYear || self::overlaps($taken, $match['offset'], $length)) {
                    continue;
                }

                if ($context->dated() && $written !== null && $year >= $written) {
                    continue;
                }

                if (self::isHistory($text->plain, $match['offset'], $length, $phrases->history, ['{year}' => (string) $year])) {
                    continue;
                }

                $taken[] = [$match['offset'], $length];
                [$from] = $text->sentenceRange($match['offset'], $length);

                // One finding a sentence: the model rewrites the whole sentence.
                if (isset($sentences[$from])) {
                    continue;
                }

                $sentences[$from] = true;
                $anchor = $text->sentenceAnchor($match['offset'], $length);

                yield Finding::make(Category::OutOfDate, self::KIND, $anchor, Needs::Words, new Message('suggest.finding.past-year', [
                    'quote' => $match['text'],
                    'year' => $year,
                    'now' => $thisYear,
                ]), ['year' => $year, 'phrase' => $match['text'], 'phraseOffset' => $match['offset'] - $from]);
            }
        }
    }

    /** A phrase with this year in it becomes a past year on 1 January. */
    public function watch(CheckContext $context): array
    {
        $phrases = $context->phrases();
        $thisYear = $context->now->format('Y');

        if ($phrases === null || $phrases->current === []) {
            return [];
        }

        $pattern = self::alternation($phrases->current, ['{year}' => preg_quote($thisYear, '/')]);

        foreach ($context->texts() as $text) {
            if ($text->matches($pattern) !== []) {
                return [new \DateTimeImmutable(((int) $thisYear + 1).'-01-01')];
            }
        }

        return [];
    }
}
