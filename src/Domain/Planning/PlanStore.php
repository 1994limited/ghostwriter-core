<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Planning;

/**
 * Where an addon keeps the content plan: its ideas (Statamic one YAML file
 * in the project, Craft `idea` documents, Filament `ghostwriter_ideas`
 * rows) and the plan screen's working state.
 *
 * The rules a store must keep are in tests/Contracts/PlanStoreContract.php.
 */
interface PlanStore
{
    /**
     * Every idea, in the order they were added (oldest first).
     *
     * @return array<int, Idea>
     */
    public function ideas(): array;

    public function find(int|string $id): ?Idea;

    /**
     * Adds the idea, or saves it over the one with its ID. An idea with no
     * ID is given one, and returned with it.
     */
    public function save(Idea $idea): Idea;

    /**
     * Nothing happens for an idea that has gone.
     */
    public function delete(int|string $id): void;

    /**
     * The plan screen's state; an empty one when nothing is stored.
     */
    public function state(): PlanState;

    public function saveState(PlanState $state): void;
}
