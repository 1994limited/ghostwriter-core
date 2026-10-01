<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A request that never got an answer, for MockHttpClient to throw.
 *
 *     $mock->queue(NetworkError::connectFailed());   // retried
 *     $mock->queue(NetworkError::timedOut(300));     // not retried
 */
final class NetworkError extends RuntimeException implements NetworkExceptionInterface
{
    private ?RequestInterface $request = null;

    public static function connectFailed(string $host = 'api.example.com'): self
    {
        return new self("cURL error 7: Failed to connect to {$host} port 443: Connection refused");
    }

    /** The response took longer than the timeout, in cURL's words. */
    public static function timedOut(int $seconds = 300): self
    {
        return new self("cURL error 28: Operation timed out after {$seconds}000 milliseconds with 0 bytes received");
    }

    public function withRequest(RequestInterface $request): self
    {
        $copy = new self($this->getMessage(), $this->getCode(), $this->getPrevious());
        $copy->request = $request;

        return $copy;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request ?? throw new \LogicException('This network error was never thrown for a request.');
    }
}
