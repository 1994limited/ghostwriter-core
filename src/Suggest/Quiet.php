<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * A decision that keeps a finding or suggestion quiet: "It's still right"
 * (confirmed) or Dismiss. It lasts Quieted::MONTHS (12) months, or until
 * the passage it was about is edited, whichever comes first.
 */
final class Quiet
{
    public const DISMISSED = 'dismissed';

    public const CONFIRMED = 'confirmed';

    public function __construct(
        public readonly string $key,
        public readonly ?string $passage,
        public readonly string $until,
        public readonly string $state = self::DISMISSED,
        public readonly int|string|null $by = null,
        public readonly ?string $at = null,
    ) {}

    /** A decision taken now, on what an anchor points at. */
    public static function of(string $key, Anchor $anchor, DateTimeImmutable $now, string $state = self::DISMISSED, int|string|null $by = null): self
    {
        return new self($key, $anchor->passage, Quieted::until($now)->format(DATE_ATOM), $state, $by, $now->format(DATE_ATOM));
    }

    /** Whether it still keeps this quiet: not expired, and the passage as it was. */
    public function covers(string $key, ?string $passage, DateTimeImmutable $now): bool
    {
        return $this->key === $key
            && new DateTimeImmutable($this->until) > $now
            && ($this->passage === null || $passage === null || $this->passage === $passage);
    }

    /**
     * @return array{key: string, passage: ?string, until: string, state: string, by: int|string|null, at: ?string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'passage' => $this->passage, 'until' => $this->until, 'state' => $this->state, 'by' => $this->by, 'at' => $this->at];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $by = $array['by'] ?? null;

        return new self(
            is_string($array['key'] ?? null) ? $array['key'] : '',
            is_string($array['passage'] ?? null) ? $array['passage'] : null,
            is_string($array['until'] ?? null) ? $array['until'] : '1970-01-01T00:00:00+00:00',
            is_string($array['state'] ?? null) ? $array['state'] : self::DISMISSED,
            is_int($by) || is_string($by) ? $by : null,
            is_string($array['at'] ?? null) ? $array['at'] : null,
        );
    }
}
