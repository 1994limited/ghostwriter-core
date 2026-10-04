<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * One comment and what followed it: the editor's comment first, then
 * replies, Ghostwriter's answers (with their changes) and system lines.
 *
 * `sentAtVersion` and `hashes` are recorded when it joins a run: the
 * review's version, and each unit's hash then (Arrange\Unit::hash()), so a
 * unit someone changed during the run is skipped rather than overwritten.
 */
final class Thread
{
    /**
     * @param  int  $number  Shown on the pin: 1, 2, 3… never reused on a piece.
     * @param  list<Note>  $notes  The first is the comment.
     * @param  array<string, string>  $hashes  Unit id => hash when it joined the run.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $number,
        public Scope $scope,
        public ThreadStatus $status,
        public array $notes,
        public int|string|null $startedBy,
        public ?string $resolvedAt = null,
        public int|string|null $resolvedBy = null,
        public ?int $sentAtVersion = null,
        public array $hashes = [],
    ) {}

    /** The editor's comment that started it. */
    public function comment(): Note
    {
        return $this->notes[0];
    }

    /**
     * What the editor has asked in it: the comment, and their replies since
     * Ghostwriter last answered, in order. What a run sends.
     *
     * @return list<Note>
     */
    public function asks(): array
    {
        $asks = [];

        foreach ($this->notes as $note) {
            if ($note->isGhostwriter()) {
                $asks = [];
            } elseif ($note->kind === NoteKind::Comment) {
                $asks[] = $note;
            }
        }

        return $asks === [] ? [$this->comment()] : $asks;
    }

    /**
     * Every change Ghostwriter made for it, oldest first.
     *
     * @return list<Change>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->notes as $note) {
            array_push($changes, ...$note->changes);
        }

        return $changes;
    }

    /** The last note Ghostwriter wrote in it, if any. */
    public function lastAnswer(): ?Note
    {
        foreach (array_reverse($this->notes) as $note) {
            if ($note->kind === NoteKind::Change || $note->kind === NoteKind::Reply) {
                return $note;
            }
        }

        return null;
    }

    /** What reopening it goes back to: Changed or Replied after Ghostwriter answered, else Not sent. */
    public function answeredStatus(): ThreadStatus
    {
        return match ($this->lastAnswer()?->kind) {
            NoteKind::Change => ThreadStatus::Changed,
            NoteKind::Reply => ThreadStatus::Replied,
            default => ThreadStatus::Open,
        };
    }

    public function isOpen(): bool
    {
        return $this->status === ThreadStatus::Open;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'scope' => $this->scope->toArray(),
            'startedBy' => $this->startedBy,
            'resolvedAt' => $this->resolvedAt,
            'resolvedBy' => $this->resolvedBy,
            'sentAtVersion' => $this->sentAtVersion,
            'hashes' => $this->hashes,
            'notes' => array_map(fn (Note $note) => $note->toArray(), $this->notes),
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $notes = array_values(array_map(fn (array $note) => Note::fromArray($note), array_filter(is_array($array['notes'] ?? null) ? $array['notes'] : [], 'is_array')));

        if ($notes === [] || ! is_scalar($array['id'] ?? null)) {
            return null;
        }

        $user = fn (string $key): int|string|null => is_int($array[$key] ?? null) || (is_string($array[$key] ?? null) && $array[$key] !== '') ? $array[$key] : null;
        $hashes = [];

        foreach (is_array($array['hashes'] ?? null) ? $array['hashes'] : [] as $unit => $hash) {
            if (is_scalar($hash)) {
                $hashes[(string) $unit] = (string) $hash;
            }
        }

        return new self(
            (string) $array['id'],
            is_int($array['number'] ?? null) ? $array['number'] : 0,
            Scope::fromArray(is_array($array['scope'] ?? null) ? $array['scope'] : []),
            ThreadStatus::tryFrom(is_string($array['status'] ?? null) ? $array['status'] : '') ?? ThreadStatus::Open,
            $notes,
            $user('startedBy'),
            is_string($array['resolvedAt'] ?? null) ? $array['resolvedAt'] : null,
            $user('resolvedBy'),
            is_int($array['sentAtVersion'] ?? null) ? $array['sentAtVersion'] : null,
            $hashes,
        );
    }
}
