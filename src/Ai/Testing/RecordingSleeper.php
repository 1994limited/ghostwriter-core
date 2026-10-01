<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper;

/**
 * Records the waits between retries instead of waiting.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var array<int, float> Every wait asked for, in seconds, in order. */
    public array $waits = [];

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
