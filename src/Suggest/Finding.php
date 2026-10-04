<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * Something a free check found, with no model: where it is, what it's
 * about, what it needs (Needs) and what to tell the editor.
 *
 * - `id` is stable: the id a suggestion of its category at its anchor has
 *   (Anchor::key()), so the model's fix for it, and a decision on it, carry
 *   over from one review to the next.
 * - `kind`: 'past-year', 'relative-time', 'closing-date', 'stated-count',
 *   'long-sentence', 'empty-link-text', 'overlap', 'external-link', or a
 *   GapKind value ('link-broken', 'missing-alt', 'seo-length', 'expected').
 * - `alone`: whether it's shown on its own in the free half. A long
 *   sentence is only a hint for the review call.
 * - `meta`: what the guide and the call need: the year, the date, the
 *   other entry, link candidates, the asset, the template for an answer.
 *   An Out of date finding is anchored on its sentence and has `phrase`
 *   (the dated words as written) and `phraseOffset` (where they start in
 *   the quote's `exact`, in characters).
 */
final class Finding
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly Category $category,
        public readonly string $kind,
        public readonly Anchor $anchor,
        public readonly Needs $needs,
        public readonly Message $message,
        public readonly array $meta = [],
        public readonly bool $alone = true,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function make(Category $category, string $kind, Anchor $anchor, Needs $needs, Message $message, array $meta = [], bool $alone = true): self
    {
        return new self($anchor->key($category), $category, $kind, $anchor, $needs, $message, $meta, $alone);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->id, $this->category, $this->kind, $this->anchor, $this->needs, $this->message, $meta + $this->meta, $this->alone);
    }

    public function withNeeds(Needs $needs): self
    {
        return new self($this->id, $this->category, $this->kind, $this->anchor, $needs, $this->message, $this->meta, $this->alone);
    }

    /**
     * The finding as a suggestion with no model, for the free half
     * (EditReviews::preview(), "Found without AI"): where it is and why, a
     * link candidate or an answer box, and never new words: a free check
     * only finds and explains. A review never falls back to it; the model
     * judges every finding. Null for a hint that isn't shown alone.
     */
    public function toSuggestion(): ?Suggestion
    {
        if (! $this->alone) {
            return null;
        }

        $reason = new Reason('', ReasonSource::Check, $this->kind, message: $this->message);
        $candidates = is_array($this->meta['candidates'] ?? null) ? $this->meta['candidates'] : [];
        $first = is_array($candidates[0] ?? null) ? $candidates[0] : null;
        $link = $first === null ? null : new LinkChange($first['value'] ?? null, is_string($first['title'] ?? null) ? $first['title'] : '', is_string($first['url'] ?? null) ? $first['url'] : null);
        $fact = null;

        if ($this->needs === Needs::Editor) {
            $template = is_string($this->meta['template'] ?? null) ? $this->meta['template'] : FactCheck::ANSWER;
            $fact = new FactCheck('', $template, null, AnswerKind::tryFrom(is_string($this->meta['answer'] ?? null) ? $this->meta['answer'] : '') ?? AnswerKind::Text);
        }

        return new Suggestion($this->id, $this->category, $this->anchor, $reason, null, [], $fact, $link, $this->id, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'kind' => $this->kind,
            'anchor' => $this->anchor->toArray(),
            'needs' => $this->needs->value,
            'message' => $this->message->toArray(),
            'meta' => $this->meta,
            'alone' => $this->alone,
        ];
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $message = is_array($array['message'] ?? null) ? $array['message'] : [];
        $params = is_array($message['params'] ?? null) ? array_filter($message['params'], fn ($value) => is_scalar($value) || $value === null) : [];

        return new self(
            is_string($array['id'] ?? null) ? $array['id'] : '',
            Category::tryFrom(is_string($array['category'] ?? null) ? $array['category'] : '') ?? Category::Clarity,
            is_string($array['kind'] ?? null) ? $array['kind'] : '',
            Anchor::fromArray(is_array($array['anchor'] ?? null) ? $array['anchor'] : []),
            Needs::tryFrom(is_string($array['needs'] ?? null) ? $array['needs'] : '') ?? Needs::Nothing,
            new Message(is_string($message['key'] ?? null) ? $message['key'] : '', array_combine(array_map('strval', array_keys($params)), array_values($params))),
            is_array($array['meta'] ?? null) ? array_combine(array_map('strval', array_keys($array['meta'])), array_values($array['meta'])) : [],
            ($array['alone'] ?? true) !== false,
        );
    }
}
