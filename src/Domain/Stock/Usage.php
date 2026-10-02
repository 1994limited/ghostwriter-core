<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;

/**
 * One place a stock image is used: the record that holds it (an entry, a
 * Filament record, a global set), on which site or locale, in which field
 * (a path into the data for a block's field), with a label for the ledger
 * screen, when it was first and last seen there, and whether that record
 * is live. A draft is still a usage: it is where a comp is previewed.
 */
final class Usage
{
    public function __construct(
        public readonly string $ownerType,
        public readonly int|string $ownerId,
        public readonly string $field,
        public readonly ?string $site = null,
        public readonly ?string $label = null,
        public readonly ?DateTimeImmutable $firstSeen = null,
        public readonly ?DateTimeImmutable $lastSeen = null,
        public readonly bool $live = false,
    ) {}

    /** The same owner and field, whenever it was seen. */
    public function key(): string
    {
        return $this->ownerType.'|'.$this->ownerId.'|'.($this->site ?? '').'|'.$this->field;
    }

    /** On this record, on this site (any site when null). */
    public function isOn(string $ownerType, int|string $ownerId, ?string $site = null): bool
    {
        return $this->ownerType === $ownerType && (string) $this->ownerId === (string) $ownerId && ($site === null || $this->site === $site);
    }

    /** Seen again now: keeps when it was first seen. */
    public function seen(DateTimeImmutable $now, ?string $label, bool $live): self
    {
        return new self($this->ownerType, $this->ownerId, $this->field, $this->site, $label ?? $this->label, $this->firstSeen ?? $now, $now, $live);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Format $format): array
    {
        return [
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'site' => $this->site,
            'field' => $this->field,
            'label' => $this->label,
            'first_seen' => $this->firstSeen !== null ? $format->stamp($this->firstSeen) : null,
            'last_seen' => $this->lastSeen !== null ? $format->stamp($this->lastSeen) : null,
            'live' => $this->live,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $ownerId = $data['owner_id'] ?? null;

        if (! is_string($data['owner_type'] ?? null) || ! (is_int($ownerId) || (is_string($ownerId) && $ownerId !== '')) || ! is_string($data['field'] ?? null)) {
            return null;
        }

        return new self(
            $data['owner_type'],
            $ownerId,
            $data['field'],
            is_scalar($data['site'] ?? null) ? (string) $data['site'] : null,
            is_string($data['label'] ?? null) ? $data['label'] : null,
            Format::parse(self::moment($data['first_seen'] ?? null)),
            Format::parse(self::moment($data['last_seen'] ?? null)),
            (bool) ($data['live'] ?? false),
        );
    }

    /** @internal */
    public static function moment(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
