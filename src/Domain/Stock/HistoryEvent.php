<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Ulid;

/**
 * One line of a stock image's audit trail: what happened, when, who did
 * it, and a few details (the order ID, the error, the field). Each has its
 * own ID, so two saves of the same record can be merged without losing or
 * doubling a line.
 */
final class HistoryEvent
{
    public const INSERTED = 'inserted';

    public const QUOTED = 'quoted';

    public const LICENSING = 'licensing';

    public const LICENSED = 'licensed';

    public const FAILED = 'failed';

    public const REPLACED = 'replaced';

    public const USAGE_ADDED = 'usage_added';

    public const USAGE_REMOVED = 'usage_removed';

    public const REMOVED = 'removed';

    public const RECONCILED = 'reconciled';

    public const COMP_REFRESHED = 'comp_refreshed';

    public const COMP_EXPIRED = 'comp_expired';

    /**
     * @param  array<string, scalar|null>  $detail
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly DateTimeImmutable $at,
        public readonly ?Person $by = null,
        public readonly array $detail = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $detail
     */
    public static function make(string $event, DateTimeImmutable $at, ?Person $by = null, array $detail = []): self
    {
        return new self(Ulid::generate((int) $at->format('Uv')), $event, $at, $by, $detail);
    }

    /**
     * @return array{id: string, event: string, at: string, by: ?array{id: int|string|null, name: ?string}, detail: array<string, scalar|null>}
     */
    public function toArray(Format $format): array
    {
        return ['id' => $this->id, 'event' => $this->event, 'at' => $format->stamp($this->at), 'by' => $this->by?->toArray(), 'detail' => $this->detail];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $at = Format::parse(Usage::moment($data['at'] ?? null));

        if (! is_string($data['id'] ?? null) || ! is_string($data['event'] ?? null) || $at === null) {
            return null;
        }

        $detail = array_filter(is_array($data['detail'] ?? null) ? $data['detail'] : [], fn ($value) => $value === null || is_scalar($value));

        return new self($data['id'], $data['event'], $at, Person::fromArray($data['by'] ?? null), array_combine(array_map('strval', array_keys($detail)), $detail));
    }
}
