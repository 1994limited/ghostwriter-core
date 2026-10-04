<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * Counts and prices about the organisation ("team of 6", "over 20 years",
 * "200 clients", "from £450") in an entry last saved at least MONTHS
 * months ago: claims to recheck. Only the editor knows today's number, so
 * each is a Fact to check, with the quote as the template around the
 * answer. Off with the site's claim-check switch (SuggestOptions).
 */
final class StatedCounts implements Check
{
    use ReadsText;

    public const KIND = 'stated-count';

    public const MONTHS = 12;

    private const DIGITS = '\d{1,3}(?:[,.\x{202F} ]\d{3})+|\d+';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $phrases = $context->phrases();

        if (! $context->options->claims || $phrases === null || $phrases->counts === [] || $context->updatedAt === null || ! $context->isOlderThan(self::MONTHS)) {
            return;
        }

        $words = $phrases->numberWords();
        $number = '(?:'.self::DIGITS.($words !== '' ? '|'.$words : '').')';
        $pattern = self::alternation($phrases->counts, ['{n}' => $number]);

        foreach ($context->texts() as $text) {
            foreach ($text->matches($pattern) as $match) {
                if (preg_match('/(?<![\p{L}\p{N}])'.$number.'(?![\p{L}\p{N}])/iu', $match['text'], $n, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $anchor = $text->anchor($match['offset'], mb_strlen($match['text']));
                $quote = $anchor->quote->exact ?? $match['text'];
                $at = mb_strlen(substr($match['text'], 0, $n[0][1]));
                $money = preg_match('/[£$€]/u', $match['text']) === 1;

                yield Finding::make(Category::FactToCheck, self::KIND, $anchor, Needs::Editor, new Message('suggest.finding.stated-count', [
                    'quote' => $quote,
                    'date' => $context->updatedAt->format('Y-m'),
                ]), [
                    'number' => $phrases->number($n[0][0]),
                    'answer' => $money ? 'money' : 'number',
                    'template' => mb_substr($quote, 0, $at).'{answer}'.mb_substr($quote, $at + mb_strlen($n[0][0])),
                ]);
            }
        }
    }
}
