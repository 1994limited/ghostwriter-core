<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Anchor;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Dates;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Watches;

/**
 * A closing or end date that has passed:
 *
 * - in text, a date with a year after a closing word ("applications close
 *   31 January 2025", "bis zum 31.01.2025"), in the page's language;
 * - in a date field whose handle reads as an end (`closing_date`,
 *   `deadline`, `ends_at`, `expires`, `valid_until`), in any language.
 *
 * Only the editor knows the new date: each is a Fact to check, with the
 * quote as the template around the answer.
 */
final class ClosingDates implements Check, Watches
{
    use ReadsText;

    public const KIND = 'closing-date';

    /** Date fields whose handle matches this hold an end. */
    public const FIELD = '/clos|deadline|(?:^|_|-)ends?(?:$|_|-|At|On)|endDate|expir|until/i';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $today = $context->now->setTime(0, 0);
        $phrases = $context->phrases();

        if ($phrases !== null && $phrases->closing !== []) {
            $cue = '/(?<![\p{L}\p{N}])(?:'.implode('|', $phrases->closing).')(?![\p{L}\p{N}])[^.!?\n]{0,20}$/iu';

            foreach ($context->texts() as $text) {
                foreach (Dates::find($text->plain, $phrases) as $date) {
                    if ($date['date'] >= $today) {
                        continue;
                    }

                    $at = $text->chars($date['offset']);
                    $before = mb_substr($text->plain, max(0, $at - 40), min($at, 40));

                    if (preg_match($cue, $before, $found, PREG_OFFSET_CAPTURE) !== 1) {
                        continue;
                    }

                    $start = $at - mb_strlen(substr($before, $found[0][1]));
                    $length = $at - $start + mb_strlen($date['text']);
                    $anchor = $text->anchor($start, $length);
                    $quote = $anchor->quote->exact ?? $date['text'];
                    $dateAt = mb_strrpos($quote, $date['text']);

                    yield Finding::make(Category::FactToCheck, self::KIND, $anchor, Needs::Editor, new Message('suggest.finding.closing-date', [
                        'quote' => $quote,
                        'date' => $date['text'],
                    ]), [
                        'date' => $date['date']->format('Y-m-d'),
                        'answer' => 'date',
                        'template' => $dateAt === false ? null : mb_substr($quote, 0, $dateAt).'{answer}'.mb_substr($quote, $dateAt + mb_strlen($date['text'])),
                    ]);
                }
            }
        }

        foreach (Walk::entry($context->gaps->schema, $context->gaps->entry) as $visit) {
            $field = $visit->field;

            if ($field->kind !== Kind::Reference || ! str_contains(strtolower($field->type), 'date') || preg_match(self::FIELD, $field->handle) !== 1) {
                continue;
            }

            $date = Dates::value($visit->value);

            if ($date === null || $date >= $today) {
                continue;
            }

            $anchor = new Anchor(AnchorScope::Field, $visit->path, $visit->label, fieldHash: Anchor::hash($date->format('Y-m-d')), passage: Anchor::hash($date->format('Y-m-d')));

            yield Finding::make(Category::FactToCheck, self::KIND, $anchor, Needs::Editor, new Message('suggest.finding.closing-date-field', [
                'label' => $visit->label,
                'date' => $date->format('Y-m-d'),
            ]), ['date' => $date->format('Y-m-d'), 'answer' => 'date', 'field' => true]);
        }
    }

    /** The day after each closing date still to come. */
    public function watch(CheckContext $context): array
    {
        $today = $context->now->setTime(0, 0);
        $days = [];
        $phrases = $context->phrases();

        if ($phrases !== null && $phrases->closing !== []) {
            $cue = '/(?<![\p{L}\p{N}])(?:'.implode('|', $phrases->closing).')(?![\p{L}\p{N}])[^.!?\n]{0,20}$/iu';

            foreach ($context->texts() as $text) {
                foreach (Dates::find($text->plain, $phrases) as $date) {
                    $at = $text->chars($date['offset']);

                    if ($date['date'] >= $today && preg_match($cue, mb_substr($text->plain, max(0, $at - 40), min($at, 40))) === 1) {
                        $days[] = $date['date']->modify('+1 day');
                    }
                }
            }
        }

        foreach (Walk::entry($context->gaps->schema, $context->gaps->entry) as $visit) {
            $field = $visit->field;

            if ($field->kind === Kind::Reference && str_contains(strtolower($field->type), 'date') && preg_match(self::FIELD, $field->handle) === 1) {
                $date = Dates::value($visit->value);

                if ($date !== null && $date >= $today) {
                    $days[] = $date->modify('+1 day');
                }
            }
        }

        return $days;
    }
}
