<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;

/**
 * A SessionStore in memory, keeping each session as its stored shape (as
 * a real store would), for core's tests and addons' unit tests.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, array<string, mixed>> */
    public array $records = [];

    /** @var array<string, int> When each was saved, to order them. */
    private array $order = [];

    private int $saves = 0;

    private int $nextKey = 1;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(private readonly Format $format, ?Closure $clock = null)
    {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function all(): array
    {
        $ids = array_keys($this->records);
        usort($ids, fn ($a, $b) => $this->order[$b] <=> $this->order[$a]);

        return array_map(fn (string $id) => Session::fromArray($this->records[$id], $this->format), $ids);
    }

    public function startedBy(int|string $userId): array
    {
        return array_values(array_filter($this->all(), fn (Session $session) => $session->startedBy !== null && (string) $session->startedBy === (string) $userId));
    }

    public function find(string $id): ?Session
    {
        return isset($this->records[$id]) ? Session::fromArray($this->records[$id], $this->format) : null;
    }

    public function save(Session $session): Session
    {
        $session->updatedAt = $this->format->stamp(($this->clock)());

        if ($this->format === Format::Filament && $session->key === null) {
            $session->key = $this->nextKey++;
        }

        $this->records[$session->id] = $session->toArray($this->format);
        $this->order[$session->id] = ++$this->saves;

        return $session;
    }

    public function delete(string $id): void
    {
        unset($this->records[$id], $this->order[$id]);
    }
}
