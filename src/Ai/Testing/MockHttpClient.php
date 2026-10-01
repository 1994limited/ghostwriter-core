<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * A PSR-18 client for tests: hands back queued responses in order, and
 * keeps every request it was sent. It is its own HttpClients too, so it can
 * be passed straight to Providers or a Transport.
 *
 *     $http = new MockHttpClient();
 *     $http->queueJson(['content' => [['type' => 'text', 'text' => 'Hi.']]]);
 *     $http->queue(NetworkError::connectFailed(), $http->response(529));
 *     // ...
 *     $http->body(0)['model'];
 *
 * PSR-17 factories default to Guzzle's (guzzlehttp/psr7), which every addon
 * has; pass your own otherwise.
 */
final class MockHttpClient implements ClientInterface, HttpClients
{
    /** @var array<int, ResponseInterface|Throwable|Closure(RequestInterface): ResponseInterface> */
    private array $queue = [];

    /** @var array<int, RequestInterface> Every request sent, in order. */
    public array $requests = [];

    /** @var array<int, int> The timeout each client was built with, in order. */
    public array $timeouts = [];

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct(
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        if (($requestFactory === null || $streamFactory === null || $responseFactory === null) && ! class_exists(HttpFactory::class)) {
            throw new LogicException('MockHttpClient needs PSR-17 factories: pass them, or install guzzlehttp/psr7.');
        }

        $guzzle = class_exists(HttpFactory::class) ? new HttpFactory : null;

        $this->requestFactory = $requestFactory ?? $guzzle ?? throw new LogicException('No request factory.');
        $this->streamFactory = $streamFactory ?? $guzzle ?? throw new LogicException('No stream factory.');
        $this->responseFactory = $responseFactory ?? $guzzle ?? throw new LogicException('No response factory.');
    }

    /**
     * Queue what the next requests get: a response, an exception to throw
     * (a NetworkError, say), or a closure given the request.
     *
     * @param  ResponseInterface|Throwable|Closure(RequestInterface): ResponseInterface  ...$answers
     */
    public function queue(ResponseInterface|Throwable|Closure ...$answers): self
    {
        $this->queue = [...$this->queue, ...array_values($answers)];

        return $this;
    }

    /**
     * Queue a JSON response.
     *
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function queueJson(array $body, int $status = 200, array $headers = []): self
    {
        return $this->queue($this->response($status, (string) json_encode($body), $headers + ['content-type' => 'application/json']));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function response(int $status = 200, string $body = '', array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $answer = array_shift($this->queue) ?? throw new LogicException(sprintf('MockHttpClient has nothing queued for %s %s.', $request->getMethod(), $request->getUri()));

        if ($answer instanceof NetworkError) {
            throw $answer->withRequest($request);
        }

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer instanceof Closure ? $answer($request) : $answer;
    }

    /** How many queued answers are left. */
    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * The JSON body of the request at $index, decoded.
     *
     * @return array<string, mixed>
     */
    public function body(int $index = 0): array
    {
        $request = $this->requests[$index] ?? throw new LogicException("No request was sent at index {$index}.");
        $data = json_decode((string) $request->getBody(), true);

        return is_array($data) ? $data : [];
    }

    public function client(int $timeout): ClientInterface
    {
        $this->timeouts[] = $timeout;

        return $this;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }
}
