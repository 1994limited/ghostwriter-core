<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Queue;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;

/**
 * Work sent to the queue and not yet picked up. A job is marked when it is
 * sent and unmarked the moment a worker starts it, so work still marked
 * after AFTER seconds most likely has no worker listening, and the screen
 * can say so rather than spin on (Filament's notice, now everywhere).
 *
 *     $waiting->queued('session:'.$id);        // when dispatching
 *     $waiting->started('session:'.$id);       // first thing in the job
 *     $waiting->notice('session:'.$id, 'php artisan queue:work');   // for the screen
 *
 * On a queue that runs work itself (sync, Craft's own queue run by the
 * control panel) there is nothing to wait for: pass `$runsItself`.
 */
final class Waiting
{
    /** Seconds before waiting work is mentioned. */
    public const AFTER = 30;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly WaitingStore $store,
        private readonly DomainOptions $options,
        private readonly bool $runsItself = false,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function queued(string $subject): void
    {
        $this->store->mark($subject, ($this->clock)()->getTimestamp());
    }

    public function started(string $subject): void
    {
        $this->store->unmark($subject);
    }

    /**
     * Seconds the work has waited for a worker, or null once one has it.
     */
    public function waited(string $subject): ?int
    {
        $at = $this->store->markedAt($subject);

        return $at === null ? null : max(0, ($this->clock)()->getTimestamp() - $at);
    }

    public function isWaiting(string $subject): bool
    {
        if ($this->runsItself) {
            return false;
        }

        $waited = $this->waited($subject);

        return $waited !== null && $waited >= self::AFTER;
    }

    /**
     * A word when the work has waited too long, naming the command that
     * starts a worker. Null while there is nothing to say.
     */
    public function notice(string $subject, string $command): ?string
    {
        return $this->isWaiting($subject) ? str_replace('{command}', $command, $this->options->queueNotice) : null;
    }
}
