<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * Ghostwriter is already answering a request on this piece: one run at a
 * time (E7). `waitingOn` is the person whose request it is, when that is
 * someone other than the one asking, so the message can say whose:
 *
 *     catch (Busy $busy) {
 *         abort(409, $busy->messageFor(fn ($id) => $people->name($id)));
 *     }
 */
final class Busy extends Conflict
{
    public function __construct(
        string $message,
        public readonly int|string|null $waitingOn = null,
        private readonly string $otherMessage = DomainOptions::WAITING_ON_OTHER,
    ) {
        parent::__construct($message);
    }

    /**
     * "Daniel is waiting on Ghostwriter. Try again when it has answered."
     * when someone else's request is running; the plain message otherwise.
     *
     * @param  callable(int|string): string  $nameOf  A user's name as the CMS shows it.
     */
    public function messageFor(callable $nameOf): string
    {
        if ($this->waitingOn === null) {
            return $this->getMessage();
        }

        return str_replace('{name}', $nameOf($this->waitingOn), $this->otherMessage);
    }
}
