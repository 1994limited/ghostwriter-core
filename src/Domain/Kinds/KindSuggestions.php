<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\WorkState;

/**
 * Kinds of content Ghostwriter has suggested for one group, waiting for a
 * person to learn them or turn them down, and when it last looked.
 *
 * A group is looked at when someone asks (Q3: only Get started looks by
 * itself), and is due another look once enough has been published there
 * since (RECHECK_AFTER).
 *
 * Kept per group: in Statamic's `kinds.json` and Craft's `kinds` state,
 * keyed by group; as Filament's `kinds:<resource>` state row.
 */
final class KindSuggestions
{
    use RoundTrips;
    use WorkState;

    /** New published records since the last look that make another one worthwhile. */
    public const RECHECK_AFTER = 10;

    /**
     * @param  array<int, array<string, mixed>>  $suggestions  Each with an `id`, `title`, `description`, `why`, `examples` and more.
     * @param  array<int, string>  $dismissed  Titles turned down, never suggested again.
     * @param  int  $records  Published records when it last looked.
     * @param  array<string, mixed>|null  $learning  Filament's learning queue, kept as it is.
     */
    public function __construct(
        public readonly Format $format,
        string $status = 'idle',
        ?string $error = null,
        public ?string $checkedAt = null,
        public int $records = 0,
        public array $suggestions = [],
        public array $dismissed = [],
        public ?array $learning = null,
    ) {
        $this->status = $status;
        $this->error = $error;
    }

    public static function empty(Format $format): self
    {
        return new self($format, learning: $format === Format::Filament ? ['status' => 'idle', 'error' => null, 'queue' => []] : null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Format $format): self
    {
        $checked = $format === Format::Craft ? ($data['checkedAt'] ?? null) : ($data['checked_at'] ?? null);
        $records = $format === Format::Filament ? ($data['records'] ?? 0) : ($data['entries'] ?? 0);

        $state = new self(
            $format,
            is_string($data['status'] ?? null) ? $data['status'] : 'idle',
            is_scalar($data['error'] ?? null) ? (string) $data['error'] : null,
            is_scalar($checked) ? (string) $checked : null,
            is_numeric($records) ? (int) $records : 0,
            array_values(array_filter((array) ($data['suggestions'] ?? []), 'is_array')),
            array_values(array_filter((array) ($data['dismissed'] ?? []), 'is_string')),
            $format === Format::Filament ? (is_array($data['learning'] ?? null) ? $data['learning'] : ['status' => 'idle', 'error' => null, 'queue' => []]) : null,
        );

        return $state->remember($data, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    /**
     * What a look found: replaces the list, each suggestion given an ID.
     *
     * @param  array<int, array<string, mixed>>  $suggestions
     * @param  int  $records  Published records now.
     */
    public function store(array $suggestions, int $records, ?DateTimeInterface $now = null): void
    {
        $this->succeed();
        $this->checkedAt = ($now ?? new DateTimeImmutable)->format(DATE_ATOM);
        $this->records = $records;
        $this->suggestions = array_values(array_map(fn (array $suggestion) => ['id' => $this->newId()] + $suggestion, $suggestions));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->suggestions as $suggestion) {
            if (($suggestion['id'] ?? null) === $id) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * Take a suggestion off the list: learned, or turned down. Turned down,
     * its title is remembered, so it is not suggested again.
     */
    public function remove(string $id, bool $dismissed = false): void
    {
        $gone = $this->find($id);

        $this->suggestions = array_values(array_filter($this->suggestions, fn (array $suggestion) => ($suggestion['id'] ?? null) !== $id));

        if ($dismissed && $gone !== null && is_string($gone['title'] ?? null)) {
            $this->dismissed = array_values(array_unique([...$this->dismissed, $gone['title']]));
        }
    }

    /**
     * Whether the group is due a look: never looked at, or with enough
     * published since; never while a look runs or a failure is shown, nor
     * with fewer than two published records to compare.
     */
    public function due(int $published): bool
    {
        if ($this->status !== 'idle' || $published < 2) {
            return false;
        }

        return $this->checkedAt === null || $published >= $this->records + self::RECHECK_AFTER;
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        $data = [
            'status' => $this->status,
            'error' => $this->error,
            ($format === Format::Craft ? 'checkedAt' : 'checked_at') => $this->checkedAt,
            ($format === Format::Filament ? 'records' : 'entries') => $this->records,
            'suggestions' => $this->suggestions,
            'dismissed' => $this->dismissed,
        ];

        if ($format === Format::Filament) {
            $data['learning'] = $this->learning ?? ['status' => 'idle', 'error' => null, 'queue' => []];
        }

        return $data;
    }
}
