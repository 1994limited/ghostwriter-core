<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * What the reviser said for one comment, as it said it (unvalidated):
 * its reply, and any whole units, replacements of exact words, a new
 * arrangement of the comment's block, and changed or removed extra items.
 */
final class RevisionItem
{
    /**
     * @param  int  $comment  The thread's number.
     * @param  array<string, string>  $units  Unit id => its whole new text.
     * @param  list<array{unit: string, exact: string, with: string}>  $replace
     * @param  list<mixed>|null  $layout  The blocks to put in place of the comment's, in the layout planner's form.
     * @param  array<string, array{text: string, parts: array<string, string>}|null>  $extras  Item id => its new text and parts; null removes it.
     */
    public function __construct(
        public readonly int $comment,
        public readonly string $reply = '',
        public readonly array $units = [],
        public readonly array $replace = [],
        public readonly ?array $layout = null,
        public readonly array $extras = [],
    ) {}

    /** Whether it changes anything, or only replies. */
    public function changes(): bool
    {
        return $this->units !== [] || $this->replace !== [] || $this->layout !== null || $this->extras !== [];
    }

    /**
     * Every unit or extra item it touches.
     *
     * @return list<string>
     */
    public function touched(): array
    {
        return array_values(array_unique([...array_keys($this->units), ...array_column($this->replace, 'unit'), ...array_keys($this->extras)]));
    }
}
