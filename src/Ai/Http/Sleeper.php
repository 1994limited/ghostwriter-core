<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

/**
 * How the transport waits between retries. Tests pass a recording sleeper,
 * so they don't wait.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
