<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * What the review call (or calls, for a long page) returned, read but not
 * yet validated: each suggestion as the model wrote it, with the batch it
 * came from. SuggestionValidator decides what is kept.
 */
final class SuggestionReply
{
    /**
     * @param  list<array{batch: int, item: array<string, mixed>}>  $items
     * @param  list<string>  $problems  Why a call's reply couldn't be read (no reply text).
     * @param  list<string>  $attached  Finding ids whose picture went with the call.
     */
    public function __construct(
        public readonly array $items = [],
        public readonly int $calls = 1,
        public readonly int $truncated = 0,
        public readonly array $problems = [],
        public readonly array $attached = [],
    ) {}

    /**
     * Whether every call's reply failed to read. Never true without a
     * problem to say why: a reply with no calls in it hasn't been read.
     */
    public function unreadable(): bool
    {
        return $this->items === [] && $this->problems !== [] && count($this->problems) >= $this->calls;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['items' => $this->items, 'calls' => $this->calls, 'truncated' => $this->truncated, 'problems' => $this->problems, 'attached' => $this->attached];
    }
}
