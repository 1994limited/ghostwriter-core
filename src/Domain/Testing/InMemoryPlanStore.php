<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;

/**
 * A PlanStore in memory, keeping stored shapes.
 */
final class InMemoryPlanStore implements PlanStore
{
    /** @var array<string, array<string, mixed>> In the order added. */
    public array $records = [];

    /** @var array<string, mixed>|null */
    public ?array $stateRecord = null;

    private int $nextId = 1;

    public function __construct(private readonly Format $format) {}

    public function ideas(): array
    {
        return array_values(array_map(fn (array $record) => Idea::fromArray($record, $this->format), $this->records));
    }

    public function find(int|string $id): ?Idea
    {
        return isset($this->records[(string) $id]) ? Idea::fromArray($this->records[(string) $id], $this->format) : null;
    }

    public function save(Idea $idea): Idea
    {
        if ($idea->id === null) {
            $idea->id = $this->format === Format::Filament ? $this->nextId++ : $this->format->newId();
        }

        $this->records[(string) $idea->id] = $idea->toArray($this->format);

        return $idea;
    }

    public function delete(int|string $id): void
    {
        unset($this->records[(string) $id]);
    }

    public function state(): PlanState
    {
        return $this->stateRecord === null ? PlanState::empty($this->format) : PlanState::fromArray($this->stateRecord, $this->format);
    }

    public function saveState(PlanState $state): void
    {
        $this->stateRecord = $state->toArray($this->format);
    }
}
