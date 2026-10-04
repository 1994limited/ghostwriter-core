<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * One message in a comment's thread: the editor's comment or reply,
 * Ghostwriter's reply (with the changes it made), or a system line. The
 * body is markdown, escaped when shown (C6), at most MAX_BODY characters.
 */
final class Note
{
    public const MAX_BODY = 2000;

    public readonly string $body;

    /**
     * @param  int|string|null  $by  The user's id; null for Ghostwriter.
     * @param  list<Change>  $changes  On a Change note: what it changed.
     */
    public function __construct(
        public readonly string $id,
        public readonly NoteKind $kind,
        public readonly int|string|null $by,
        string $body,
        public readonly string $at,
        public readonly array $changes = [],
    ) {
        $this->body = mb_substr(trim($body), 0, self::MAX_BODY);
    }

    public function isGhostwriter(): bool
    {
        return $this->by === null && $this->kind !== NoteKind::Comment;
    }

    public function withBody(string $body): self
    {
        return new self($this->id, $this->kind, $this->by, $body, $this->at, $this->changes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind->value, 'by' => $this->by, 'body' => $this->body, 'at' => $this->at]
            + ($this->changes !== [] ? ['changes' => array_map(fn (Change $change) => $change->toArray(), $this->changes)] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $by = $array['by'] ?? null;

        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            NoteKind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '') ?? NoteKind::System,
            is_int($by) || (is_string($by) && $by !== '') ? $by : null,
            is_scalar($array['body'] ?? null) ? (string) $array['body'] : '',
            is_scalar($array['at'] ?? null) ? (string) $array['at'] : '',
            array_values(array_map(fn (array $change) => Change::fromArray($change), array_filter(is_array($array['changes'] ?? null) ? $array['changes'] : [], 'is_array'))),
        );
    }
}
