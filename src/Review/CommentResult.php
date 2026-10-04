<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;

/**
 * Ghostwriter's answer to one comment: an item of its answer message in
 * the conversation. Changed (with a Change per unit, before and after),
 * Replied, Refused (with the validator's rules), Skipped or Failed, each
 * with a reply in plain words. `quote` is a comment on some words moved to
 * the words that took their place. Put back and Resolve are noted here.
 */
final class CommentResult
{
    /**
     * @param  list<Change>  $changes
     * @param  list<string>  $rules  RevisionValidator's, for a refused one.
     * @param  array{by: int|string|null, at: string}|null  $resolved
     * @param  array{by: int|string|null, at: string}|null  $putBack
     */
    public function __construct(
        public readonly int $number,
        public readonly string $id,
        public readonly CommentOutcome $outcome,
        public readonly string $reply = '',
        public readonly array $changes = [],
        public readonly array $rules = [],
        public readonly ?TextQuote $quote = null,
        public readonly ?array $resolved = null,
        public readonly ?array $putBack = null,
    ) {}

    /** "Put it back": its text changes, whole, not put back already. */
    public function canPutBack(): bool
    {
        $text = array_filter($this->changes, fn (Change $change) => ! $change->layout);

        return $this->putBack === null && $text !== [] && array_filter($text, fn (Change $change) => ! $change->canPutBack()) === [];
    }

    public function withResolved(int|string|null $by, ?string $at): self
    {
        return new self($this->number, $this->id, $this->outcome, $this->reply, $this->changes, $this->rules, $this->quote, $at === null ? null : ['by' => $by, 'at' => $at], $this->putBack);
    }

    public function withPutBack(int|string|null $by, string $at): self
    {
        return new self($this->number, $this->id, $this->outcome, $this->reply, $this->changes, $this->rules, $this->quote, $this->resolved, ['by' => $by, 'at' => $at]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['number' => $this->number, 'id' => $this->id, 'outcome' => $this->outcome->value, 'reply' => $this->reply]
            + ($this->changes !== [] ? ['changes' => array_map(fn (Change $change) => $change->toArray(), $this->changes)] : [])
            + ($this->rules !== [] ? ['rules' => $this->rules] : [])
            + ($this->quote !== null ? ['quote' => $this->quote->toArray()] : [])
            + ($this->resolved !== null ? ['resolved' => $this->resolved] : [])
            + ($this->putBack !== null ? ['putBack' => $this->putBack] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $outcome = CommentOutcome::tryFrom(is_string($array['outcome'] ?? null) ? $array['outcome'] : '');

        if ($outcome === null || ! is_numeric($array['number'] ?? null)) {
            return null;
        }

        $mark = function (mixed $value): ?array {
            if (! is_array($value) || ! is_string($value['at'] ?? null)) {
                return null;
            }

            $by = $value['by'] ?? null;

            return ['by' => is_int($by) || is_string($by) ? $by : null, 'at' => $value['at']];
        };
        $quote = null;

        if (is_array($array['quote'] ?? null) && is_string($array['quote']['exact'] ?? null) && trim($array['quote']['exact']) !== '') {
            $quote = new TextQuote(mb_substr($array['quote']['exact'], 0, TextQuote::MAX_EXACT), (string) ($array['quote']['prefix'] ?? ''), (string) ($array['quote']['suffix'] ?? ''));
        }

        return new self(
            (int) $array['number'],
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            $outcome,
            is_scalar($array['reply'] ?? null) ? (string) $array['reply'] : '',
            array_values(array_map(fn (array $change) => Change::fromArray($change), array_filter(is_array($array['changes'] ?? null) ? $array['changes'] : [], 'is_array'))),
            array_values(array_map('strval', array_filter(is_array($array['rules'] ?? null) ? $array['rules'] : [], 'is_scalar'))),
            $quote,
            $mark($array['resolved'] ?? null),
            $mark($array['putBack'] ?? null),
        );
    }
}
