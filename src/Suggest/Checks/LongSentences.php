<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;

/**
 * Sentences over the language's limit (30 words in English, 25 in
 * German, 35 in French and Spanish, 28 in Dutch). Only a hint for the
 * review call (`alone` false): with no rewrite from it, it isn't shown.
 * SEO values are left to SeoLength.
 */
final class LongSentences implements Check
{
    public const KIND = 'long-sentence';

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        $phrases = $context->phrases();

        if ($phrases === null) {
            return;
        }

        // SEO values have their own check, and their own limit.
        $seo = array_map(fn ($field) => $field->path->toString(), $context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? []);

        foreach ($context->texts() as $text) {
            if (in_array($text->visit->path->toString(), $seo, true)) {
                continue;
            }

            foreach ($text->blocks as $i => [$start]) {
                $block = $text->block($i);

                foreach (Sentences::split($block) as [$at, $length]) {
                    $words = count(NormalisedText::words(mb_substr($block, $at, $length)));

                    if ($words <= $phrases->longSentence) {
                        continue;
                    }

                    yield Finding::make(Category::Clarity, self::KIND, $text->anchor($start + $at, $length), Needs::Words, new Message('suggest.finding.long-sentence', [
                        'words' => $words,
                        'limit' => $phrases->longSentence,
                    ]), ['words' => $words], alone: false);
                }
            }
        }
    }
}
