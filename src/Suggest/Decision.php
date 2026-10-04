<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * One entry in a review's history: what happened to a suggestion, who did
 * it and when. Decisions are only ever added, so the history is the
 * page's: "Dismissed by Priya", "Accepted by Daniel", "Done when saved".
 * Undo adds an Open decision. `by` is null for what core decided (Done,
 * Stale, Expired).
 *
 * - `answer`: the editor's answer to a Fact to check.
 * - `text`: the words that went in, when they weren't the replacement as
 *   suggested (Edit, an alternative, a filled template).
 */
final class Decision
{
    public function __construct(
        public readonly string $suggestion,
        public readonly SuggestionState $state,
        public readonly int|string|null $by,
        public readonly string $at,
        public readonly ?string $answer = null,
        public readonly ?string $text = null,
    ) {}

    public static function now(string $suggestion, SuggestionState $state, int|string|null $by, DateTimeImmutable $now, ?string $answer = null, ?string $text = null): self
    {
        return new self($suggestion, $state, $by, $now->format(DATE_ATOM), $answer, $text);
    }

    /**
     * @return array{suggestion: string, state: string, by: int|string|null, at: string, answer: ?string, text: ?string}
     */
    public function toArray(): array
    {
        return ['suggestion' => $this->suggestion, 'state' => $this->state->value, 'by' => $this->by, 'at' => $this->at, 'answer' => $this->answer, 'text' => $this->text];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $by = $array['by'] ?? null;

        return new self(
            is_string($array['suggestion'] ?? null) ? $array['suggestion'] : '',
            SuggestionState::tryFrom(is_string($array['state'] ?? null) ? $array['state'] : '') ?? SuggestionState::Open,
            is_int($by) || is_string($by) ? $by : null,
            is_string($array['at'] ?? null) ? $array['at'] : '',
            is_string($array['answer'] ?? null) ? $array['answer'] : null,
            is_string($array['text'] ?? null) ? $array['text'] : null,
        );
    }
}
