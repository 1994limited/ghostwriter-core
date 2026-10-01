<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;

/**
 * Which failures are worth trying again, and how long to wait first.
 *
 * - Statuses that mean "not now" rather than "no" are retried: 408, 409,
 *   429, 500, 502, 503, 504 and 529. Any other status fails at once.
 * - Connection failures are retried; a timeout waiting for the response is
 *   not, because the model may still be working and a retry would double
 *   the cost and the wait. Transport tells the two apart.
 * - The wait is what the provider asked for (`retry-after-ms`, then
 *   `retry-after` in seconds or as an HTTP date), up to $maxWait. If it asks
 *   for longer than that, the call is not retried. Without a header the wait
 *   is exponential backoff with full jitter: random(0, min($maxWait, 2^attempt)).
 */
final class RetryPolicy
{
    public const RETRY_STATUSES = [408, 409, 429, 500, 502, 503, 504, 529];

    /** @var Closure(float): float */
    private readonly Closure $random;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  int  $attempts  Tries in total, the first included.
     * @param  float  $maxWait  The longest wait between tries, in seconds.
     * @param  (callable(float): float)|null  $random  A number from 0 to the given maximum. For tests.
     * @param  (callable(): float)|null  $clock  The time now, as a Unix timestamp. For tests.
     */
    public function __construct(
        public readonly int $attempts = 3,
        public readonly float $maxWait = 30.0,
        ?callable $random = null,
        ?callable $clock = null,
    ) {
        $this->random = $random !== null
            ? Closure::fromCallable($random)
            : fn (float $max): float => $max * mt_rand() / mt_getrandmax();
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : fn (): float => microtime(true);
    }

    public function retriesStatus(int $status): bool
    {
        return in_array($status, self::RETRY_STATUSES, true);
    }

    /**
     * Seconds to wait before attempt $attempt + 1, or null when the
     * provider asked for longer than $maxWait.
     *
     * @param  int  $attempt  The attempt that just failed, from 1.
     */
    public function wait(int $attempt, ?ResponseInterface $response = null): ?float
    {
        $asked = $response ? $this->asked($response) : null;

        if ($asked !== null) {
            return $asked > $this->maxWait ? null : $asked;
        }

        return ($this->random)(min($this->maxWait, 2 ** $attempt));
    }

    /**
     * How long the response asked to wait, in seconds, if it said.
     */
    public function asked(ResponseInterface $response): ?float
    {
        $milliseconds = trim($response->getHeaderLine('retry-after-ms'));

        if (is_numeric($milliseconds)) {
            return max(0.0, (float) $milliseconds / 1000);
        }

        $header = trim($response->getHeaderLine('retry-after'));

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $date = strtotime($header);

        return $date === false ? null : max(0.0, $date - ($this->clock)());
    }

    public function now(): float
    {
        return ($this->clock)();
    }
}
