<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

/**
 * Where an addon keeps its sessions: Statamic one JSON file each, Craft a
 * row each, Filament the `ghostwriter_sessions` table (within the current
 * workspace). Takes and returns sessions only; an implementation reads a
 * record with `Session::fromArray($stored, $format)` and writes
 * `$session->toArray()`.
 *
 * The rules a store must keep are in tests/Contracts/SessionStoreContract.php,
 * which each addon runs against its own store.
 */
interface SessionStore
{
    /**
     * Every session, the most recently changed first.
     *
     * @return array<int, Session>
     */
    public function all(): array;

    /**
     * Sessions started by one person, the most recently changed first.
     *
     * @return array<int, Session>
     */
    public function startedBy(int|string $userId): array;

    /**
     * Null for an ID that isn't one, or a session that has gone. Never throws
     * for a malformed ID.
     */
    public function find(string $id): ?Session;

    /**
     * Saves the session as it is, stamping `updatedAt` with now (in the
     * session's format), and returns it. A session with no row yet is
     * added; a host key (Filament's `id`) is set on it.
     */
    public function save(Session $session): Session;

    /**
     * Removes it, with anything kept beside it (a lock file). Nothing
     * happens for a session that has gone.
     */
    public function delete(string $id): void;
}
