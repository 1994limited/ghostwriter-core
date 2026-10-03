<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * One part of a unit's text, finer than the unit: a paragraph, a heading,
 * a list item, a block quote, a table or a code block in rich text, or one
 * field of a row. Layouts split and join units by their pieces.
 */
final class Piece
{
    public const PARAGRAPH = 'paragraph';

    public const HEADING = 'heading';

    public const ITEM = 'item';

    public const QUOTE = 'quote';

    public const TABLE = 'table';

    public const CODE = 'code';

    /** One field of a row, named by `field`. */
    public const FIELD = 'field';

    /**
     * @param  int  $level  A heading's level (1–6), a list item's depth (0 at the top); 0 otherwise.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $markdown,
        public readonly int $level = 0,
        public readonly ?string $field = null,
    ) {}

    /**
     * @return array{kind: string, markdown: string, level?: int, field?: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'markdown' => $this->markdown]
            + ($this->level !== 0 ? ['level' => $this->level] : [])
            + ($this->field !== null ? ['field' => $this->field] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            is_string($array['kind'] ?? null) ? $array['kind'] : self::PARAGRAPH,
            is_scalar($array['markdown'] ?? null) ? (string) $array['markdown'] : '',
            is_int($array['level'] ?? null) ? $array['level'] : 0,
            is_string($array['field'] ?? null) ? $array['field'] : null,
        );
    }
}
