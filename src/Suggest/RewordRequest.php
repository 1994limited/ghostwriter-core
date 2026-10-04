<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;

/**
 * "Write another": two more versions of a suggestion's words, from one
 * small `reworder` call, once the stored alternatives have all been shown.
 * The call sees the sentence and one either side, the reason, every
 * version shown so far, and the voice guide; never the rest of the page.
 */
final class RewordRequest
{
    /**
     * @param  list<string>  $shown  Every version shown so far, the first replacement included.
     */
    public function __construct(
        public readonly string $quote,
        public readonly string $around,
        public readonly string $reason,
        public readonly array $shown,
        public readonly string $voice = '',
    ) {}

    /**
     * For a stored suggestion, its words found again in the entry as it
     * is now.
     *
     * @param  array<int, string>  $shown  Versions shown beyond the suggestion's own.
     *
     * @throws InvalidArgumentException for a Fact to check, an alt text, or words no longer there.
     */
    public static function for(Suggestion $suggestion, CheckContext $context, string $voice = '', array $shown = []): self
    {
        if ($suggestion->category === Category::FactToCheck || $suggestion->anchor->scope === AnchorScope::Asset || $suggestion->anchor->quote === null) {
            throw new InvalidArgumentException('Only a wording suggestion can be written another way.');
        }

        $text = $context->textAt($suggestion->anchor->path->toString());
        $match = $text === null ? null : (new QuoteFinder)->find($suggestion->anchor->quote, $text->plain, $suggestion->anchor->occurrence);

        if ($text === null || $match === null) {
            throw new InvalidArgumentException('suggest.review.stale');
        }

        $sentences = Sentences::split($text->plain);
        $around = [];

        foreach ($sentences as $i => [$at, $length]) {
            if ($match->offset < $at + $length && $at < $match->offset + max(1, $match->length)) {
                foreach ([$i - 1, $i, $i + 1] as $j) {
                    if (isset($sentences[$j])) {
                        $around[$j] = mb_substr($text->plain, $sentences[$j][0], $sentences[$j][1]);
                    }
                }
            }
        }

        ksort($around);
        $versions = array_values(array_unique(array_filter([$suggestion->replacement, ...$suggestion->alternatives, ...array_values($shown)], fn ($version) => is_string($version) && $version !== '')));

        return new self($suggestion->anchor->quote->exact, implode(' ', $around), $suggestion->reason->text, $versions, $voice);
    }

    public function prompt(): string
    {
        return "<sentence>\n{$this->around}\n</sentence>\n\n<quote>{$this->quote}</quote>\n\n<reason>{$this->reason}</reason>\n\n<shown>\n- ".implode("\n- ", $this->shown)."\n</shown>\n\nGive two more versions of the quoted words.";
    }
}
