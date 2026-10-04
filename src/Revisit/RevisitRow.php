<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * One entry on the Content to revisit list: its reasons and score, and
 * what the index needs to keep it current without reading the entry.
 *
 * - `watch`: dates (Y-m-d) when a time-based check may change its answer
 *   (a closing date passing, a new year, the entry turning a year old):
 *   the daily pass scans the entry again on the first of them.
 * - `linksTo`: what its links hold (`entry::abc`, an asset reference), so
 *   a deleted entry's linkers are checked again at once.
 * - `external`: the weekly check's last result for each link to another
 *   site, by address (opt-in; LinkResult).
 */
final class RevisitRow
{
    /**
     * @param  list<RevisitReason>  $reasons
     * @param  list<string>  $watch
     * @param  list<string>  $linksTo
     * @param  array<string, LinkResult>  $external
     */
    public function __construct(
        public readonly EntryRef $entry,
        public readonly string $title,
        public readonly ?string $editUrl,
        public readonly ?string $updatedAt,
        public readonly array $reasons,
        public readonly int $score,
        public readonly string $checkedAt,
        public readonly string $contentHash,
        public readonly array $watch = [],
        public readonly array $linksTo = [],
        public readonly array $external = [],
        public readonly ?string $snoozedUntil = null,
    ) {}

    public function priority(): string
    {
        return Priority::word($this->score);
    }

    public function has(ReasonKind $kind): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->kind === $kind) {
                return true;
            }
        }

        return false;
    }

    public function isSnoozed(\DateTimeImmutable $now): bool
    {
        return $this->snoozedUntil !== null && new \DateTimeImmutable($this->snoozedUntil) > $now;
    }

    /** Snoozed for $days days (90 from the list's row menu), for everyone on the site. */
    public function snooze(\DateTimeImmutable $now, int $days = 90): self
    {
        return $this->with(snoozedUntil: $now->modify("+{$days} days")->format(DATE_ATOM));
    }

    /**
     * @param  list<RevisitReason>|null  $reasons
     * @param  array<string, LinkResult>|null  $external
     */
    public function with(?array $reasons = null, ?int $score = null, ?string $checkedAt = null, ?array $external = null, ?string $snoozedUntil = null): self
    {
        return new self(
            $this->entry, $this->title, $this->editUrl, $this->updatedAt,
            $reasons ?? $this->reasons, $score ?? $this->score, $checkedAt ?? $this->checkedAt, $this->contentHash,
            $this->watch, $this->linksTo, $external ?? $this->external, $snoozedUntil ?? $this->snoozedUntil,
        );
    }

    /**
     * The external links whose last two checks found them gone.
     *
     * @return list<LinkResult>
     */
    public function brokenExternal(): array
    {
        return array_values(array_filter($this->external, fn (LinkResult $result) => $result->isBroken()));
    }

    /**
     * For storage and the list. `reasons` carry their message and severity.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entry' => $this->entry->toArray(),
            'key' => $this->entry->key(),
            'title' => $this->title,
            'editUrl' => $this->editUrl,
            'updatedAt' => $this->updatedAt,
            'reasons' => array_map(fn (RevisitReason $reason) => $reason->toArray(), $this->reasons),
            'score' => $this->score,
            'priority' => $this->priority(),
            'checkedAt' => $this->checkedAt,
            'contentHash' => $this->contentHash,
            'watch' => $this->watch,
            'linksTo' => $this->linksTo,
            'external' => array_values(array_map(fn (LinkResult $result) => $result->toArray(), $this->external)),
            'snoozedUntil' => $this->snoozedUntil,
        ];
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $strings = fn (string $key) => array_values(array_filter(is_array($array[$key] ?? null) ? $array[$key] : [], 'is_string'));
        $string = fn (string $key) => is_string($array[$key] ?? null) ? $array[$key] : null;
        $external = [];

        foreach (is_array($array['external'] ?? null) ? $array['external'] : [] as $result) {
            if (is_array($result)) {
                $result = LinkResult::fromArray($result);
                $external[$result->url] = $result;
            }
        }

        return new self(
            EntryRef::fromArray(is_array($array['entry'] ?? null) ? $array['entry'] : []),
            $string('title') ?? '',
            $string('editUrl'),
            $string('updatedAt'),
            array_values(array_filter(array_map(fn ($reason) => is_array($reason) ? RevisitReason::fromArray($reason) : null, is_array($array['reasons'] ?? null) ? $array['reasons'] : []))),
            is_int($array['score'] ?? null) ? $array['score'] : 0,
            $string('checkedAt') ?? '',
            $string('contentHash') ?? '',
            $strings('watch'),
            $strings('linksTo'),
            $external,
            $string('snoozedUntil'),
        );
    }
}
