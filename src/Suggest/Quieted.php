<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;

/**
 * Decisions that keep findings quiet on one entry, so a dismissed finding
 * or a fact confirmed with "It's still right" isn't raised again by the
 * free checks, the revisit list or the review call, for MONTHS months or
 * until its passage is edited. EditReviews::quieted() builds it from an
 * entry's review history; CheckContext takes it.
 */
final class Quieted
{
    public const MONTHS = 12;

    /** @var list<Quiet> */
    private readonly array $quiets;

    /**
     * @param  array<int, Quiet>  $quiets
     */
    public function __construct(array $quiets = [])
    {
        $this->quiets = array_values($quiets);
    }

    /** When a decision taken now stops keeping things quiet. */
    public static function until(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.self::MONTHS.' months');
    }

    public function covers(string $key, ?string $passage, DateTimeImmutable $now): bool
    {
        foreach ($this->quiets as $quiet) {
            if ($quiet->covers($key, $passage, $now)) {
                return true;
            }
        }

        return false;
    }

    public function coversFinding(Finding $finding, DateTimeImmutable $now): bool
    {
        return $this->covers($finding->id, $finding->anchor->passage, $now);
    }

    public function with(Quiet ...$quiets): self
    {
        return new self([...$this->quiets, ...array_values($quiets)]);
    }

    /**
     * @return list<Quiet>
     */
    public function all(): array
    {
        return $this->quiets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Quiet $quiet) => $quiet->toArray(), $this->quiets);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(array_values(array_map(fn (array $quiet) => Quiet::fromArray($quiet), array_filter($array, 'is_array'))));
    }
}
