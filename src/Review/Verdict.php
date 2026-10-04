<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * What RevisionValidator made of the reviser's item for one comment: the
 * rules it broke (none: it may be applied), what it would change, and
 * what is only worth saying (a much shorter text, a layout kept).
 */
final class Verdict
{
    /**
     * @param  list<string>  $rules  RevisionValidator's rules it broke; empty when it may be applied.
     * @param  array<string, string>  $units  Unit id => its new whole text.
     * @param  array<string, array{text: string, parts: array<string, string>}|null>  $extras  Extra item id => its new text and parts; null removes it.
     * @param  array<string, list<array{ask: string, value: string, by: int|string|null}>>  $filled  Unit or item id => the asks it fills from the editor's words.
     * @param  list<mixed>|null  $layout  A new arrangement asked for, as the reviser gave it (checked by RevisionValidator::layout()).
     * @param  list<string>  $warnings  RevisionValidator's rules worth a word in the reply, not a refusal: size.
     * @param  list<string>  $unsourced  The facts that had no source, for the reply.
     * @param  array<string, mixed>|null  $data  The draft data with the change made, when it passed.
     */
    public function __construct(
        public readonly Comment $comment,
        public readonly string $reply,
        public readonly array $rules = [],
        public readonly array $units = [],
        public readonly array $extras = [],
        public readonly array $filled = [],
        public readonly ?array $layout = null,
        public readonly array $warnings = [],
        public readonly array $unsourced = [],
        public readonly ?array $data = null,
    ) {}

    /** Whether it may be applied. */
    public function passes(): bool
    {
        return $this->rules === [];
    }

    /** Whether it changes text (or a layout), rather than only replying. */
    public function changes(): bool
    {
        return $this->units !== [] || $this->extras !== [] || $this->layout !== null;
    }

    /**
     * Every unit and extra item it changes.
     *
     * @return list<string>
     */
    public function touched(): array
    {
        return [...array_keys($this->units), ...array_keys($this->extras)];
    }
}
