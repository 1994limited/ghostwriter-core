<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/** Where a review's run is. There is no overnight review: a run starts only when someone clicks. */
enum ReviewStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';

    /** The call failed or its reply couldn't be read; the free findings stand. */
    case Failed = 'failed';

    public function isRunning(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
