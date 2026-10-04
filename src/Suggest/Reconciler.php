<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;

/**
 * After the entry is saved (or a stored review is opened again): which
 * suggestions the saved text now has, and which no longer fit it. No
 * model; the content is the truth.
 *
 * For each suggestion still open or accepted:
 * - **Done** when the field has its new words (and, where they were in
 *   the old words already, the old words have gone): the replacement, an
 *   alternative, a version written later, the words the editor put in
 *   (Edit), a Fact to check's template filled with the answer, or its
 *   version without; for a link, the new target; for alt text, the asset's
 *   alt (AssetAlt).
 * - **Stale** when its quote is gone and none of those is there, or a
 *   whole-value field changed to something else.
 * - Otherwise it stays as it was.
 */
final class Reconciler
{
    private readonly QuoteFinder $quotes;

    public function __construct(?QuoteFinder $quotes = null)
    {
        $this->quotes = $quotes ?? new QuoteFinder;
    }

    public function reconcile(EditReview $review, CheckContext $saved, DateTimeImmutable $now): EditReview
    {
        foreach ($review->all() as $suggestion) {
            if (! in_array($suggestion->state, [SuggestionState::Open, SuggestionState::Accepted], true)) {
                continue;
            }

            $state = $this->stateOf($suggestion, $review, $saved);

            if ($state !== null) {
                $review->settle($suggestion->id, $state, $now);
            }
        }

        return $review;
    }

    private function stateOf(Suggestion $suggestion, EditReview $review, CheckContext $saved): ?SuggestionState
    {
        $anchor = $suggestion->anchor;

        if ($anchor->scope === AnchorScope::Asset) {
            $alt = $anchor->asset !== null ? $saved->gaps->alt?->altFor($anchor->asset) : null;

            if ($alt === null || $alt === '') {
                return null;
            }

            return in_array(self::norm($alt), array_map(self::norm(...), $this->newWords($suggestion, $review)), true) ? SuggestionState::Done : SuggestionState::Stale;
        }

        $text = $saved->textAt($anchor->path->toString());
        $plain = $text === null ? '' : $text->plain;
        $candidates = array_filter(array_map(self::norm(...), $this->newWords($suggestion, $review)), fn (string $words) => $words !== '');

        if ($anchor->scope === AnchorScope::Field) {
            if (in_array(self::norm($plain), $candidates, true) || ($suggestion->link !== null && $text !== null && self::links($text->markdown, $suggestion->link->target))) {
                return SuggestionState::Done;
            }

            return $anchor->fieldHash !== null && Anchor::hash($plain) !== $anchor->fieldHash ? SuggestionState::Stale : null;
        }

        $haystack = ' '.self::norm($plain).' ';
        $found = $anchor->quote !== null && $text !== null && $this->quotes->find($anchor->quote, $plain, $anchor->occurrence) !== null;
        $quote = $anchor->quote !== null ? self::norm($anchor->quote->exact) : '';

        foreach ($candidates as $words) {
            // New words that were in the old ones already ("Winter care
            // visits" in "New for 2024: winter care visits") count only once
            // the old words have gone, unless they hold them.
            if (str_contains($haystack, ' '.$words.' ') && (! $found || ($quote !== '' && str_contains(' '.$words.' ', ' '.$quote.' ')))) {
                return SuggestionState::Done;
            }
        }

        if ($suggestion->replacement === null && $suggestion->link !== null && $text !== null && self::links($text->markdown, $suggestion->link->target)) {
            return SuggestionState::Done;
        }

        return $found ? null : SuggestionState::Stale;
    }

    /**
     * Every form the change may have gone in as.
     *
     * @return list<string>
     */
    private function newWords(Suggestion $suggestion, EditReview $review): array
    {
        $words = [$suggestion->replacement, ...$suggestion->alternatives, ...($review->versions[$suggestion->id] ?? [])];

        foreach ($review->decisions as $decision) {
            if ($decision->suggestion !== $suggestion->id) {
                continue;
            }

            $words[] = $decision->text;

            if ($decision->answer !== null && $suggestion->fact !== null) {
                try {
                    $words[] = $suggestion->fact->fill($decision->answer);
                } catch (InvalidArgumentException) {
                }
            }
        }

        $words[] = $suggestion->fact?->without;

        return array_values(array_filter($words, fn ($word) => is_string($word) && trim($word) !== ''));
    }

    private static function norm(string $text): string
    {
        return implode(' ', NormalisedText::words($text));
    }

    private static function links(string $markdown, mixed $target): bool
    {
        return is_string($target) && $target !== '' && str_contains($markdown, '('.$target);
    }
}
