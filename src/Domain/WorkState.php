<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

use DateTimeInterface;

/**
 * The working state of a screen's background work (looking for ideas,
 * scanning for the voice guide, studying a collection): idle, working or
 * failed, with the last error and what is being done.
 *
 * A failure is shown once, then forgotten (forgetFailure()). Work still
 * marked as working long after any job could still be running has stopped
 * without saying so, and is shown as failed (CRA-2), timed from when the
 * state was last changed, which the store knows.
 *
 * @internal
 */
trait WorkState
{
    public string $status = 'idle';

    public ?string $error = null;

    public ?string $task = null;

    /** When the state was last saved, as the store knows it (not part of the record). */
    public DateTimeInterface|string|int|null $changedAt = null;

    public function isWorking(): bool
    {
        return $this->status === 'working';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * @throws Conflict when work is already running
     */
    public function begin(?string $task = null, string $busy = 'Ghostwriter is still working on the last request.'): void
    {
        if ($this->isWorking()) {
            throw new Conflict($busy);
        }

        $this->status = 'working';
        $this->error = null;
        $this->task = $task;
    }

    public function succeed(): void
    {
        $this->status = 'idle';
        $this->error = null;
        $this->task = null;
    }

    public function fail(string $error): void
    {
        $this->status = 'failed';
        $this->error = $error;
        $this->task = null;
    }

    /**
     * A failure is reported once. After that the screen starts clean, so an
     * old error does not greet every visit. Whether anything changed.
     */
    public function forgetFailure(): bool
    {
        if (! $this->hasFailed()) {
            return false;
        }

        $this->succeed();

        return true;
    }

    /**
     * Timed from `changedAt`, which the store sets when it reads the state.
     */
    public function isStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        $since = Format::parse($this->changedAt);

        return $this->isWorking() && $since !== null && $since->getTimestamp() < ($now ?? new \DateTimeImmutable)->getTimestamp() - $options->staleAfter();
    }

    /**
     * Whether anything changed.
     */
    public function recoverIfStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        if (! $this->isStale($options, $now)) {
            return false;
        }

        $this->fail(DomainOptions::STOPPED);

        return true;
    }
}
