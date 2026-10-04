<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Check;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Shingles;

/**
 * A paragraph that shares over SHARE of its word shingles with a paragraph
 * of another entry (EntryIndex): said before. Only with an index and the
 * entry's reference; the revisit scan leaves it out (too slow per entry).
 */
final class Overlaps implements Check
{
    public const KIND = 'overlap';

    public const SHARE = 0.5;

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function find(CheckContext $context): iterable
    {
        if ($context->index === null || $context->entry === null) {
            return;
        }

        foreach ($context->texts() as $text) {
            foreach ($text->blocks as $i => [$start, $length]) {
                $block = $text->block($i);

                if (count(NormalisedText::words($block)) < Shingles::MIN_WORDS) {
                    continue;
                }

                $shingles = Shingles::of($block);
                $best = null;
                $share = 0.0;

                foreach ($context->index->sharing($shingles, $context->entry) as $paragraph) {
                    if ($paragraph->entry->is($context->entry)) {
                        continue;
                    }

                    $shared = Shingles::share($shingles, $paragraph->shingles);

                    if ($shared > $share) {
                        [$best, $share] = [$paragraph, $shared];
                    }
                }

                if ($best === null || $share <= self::SHARE) {
                    continue;
                }

                yield Finding::make(Category::Duplicate, self::KIND, $text->anchor($start, $length), Needs::Words, new Message('suggest.finding.overlap', [
                    'title' => $best->title,
                    'share' => (int) round($share * 100),
                ]), [
                    'entry' => $best->entry->key(),
                    'title' => $best->title,
                    'url' => $best->url,
                    'share' => round($share, 2),
                ]);
            }
        }
    }
}
