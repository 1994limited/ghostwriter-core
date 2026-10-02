<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use InvalidArgumentException;

/**
 * A model answered, but not in a form Studio could read. The message is for
 * the person who asked; `problem` says what was wrong, without quoting the
 * reply.
 *
 * It is an InvalidArgumentException, which is what the addons' Studios
 * threw, so their existing catches keep working.
 */
class UnreadableReply extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $agent,
        public readonly string $problem,
    ) {
        parent::__construct($message);
    }
}
