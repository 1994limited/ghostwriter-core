<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

use Closure;
use JsonException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Unreachable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends one provider's requests: builds them, retries what is worth
 * retrying (RetryPolicy), decodes the JSON that comes back and turns every
 * failure into a ProviderException a person can read.
 *
 * Keys never reach a message or a log line: the values of the
 * `authorization`, `x-api-key` and `x-goog-api-key` headers are blanked out
 * of anything the transport reports.
 *
 * Telling a response timeout from a connection failure: PSR-18 reports both
 * as a NetworkExceptionInterface. A message saying "Operation timed out" or
 * "... bytes received" (cURL's wording once connected) is read as the
 * response taking too long, which is not retried. Anything else is a
 * connection failure, which is.
 */
final class Transport
{
    private const SECRET_HEADERS = ['authorization', 'x-api-key', 'x-goog-api-key'];

    private readonly RetryPolicy $retry;

    private readonly Sleeper $sleeper;

    private readonly LoggerInterface $logger;

    private int $attempts = 0;

    /** @var (Closure(ResponseInterface, string): ?ProviderException)|null */
    private readonly ?Closure $errors;

    /**
     * $errors is a provider's own reading of an error response, given the
     * response and its body, tried before the usual mapping; null from it
     * means the usual mapping applies.
     *
     * @param  (callable(ResponseInterface, string): ?ProviderException)|null  $errors
     */
    public function __construct(
        private readonly HttpClients $http,
        private readonly string $provider,
        ?RetryPolicy $retry = null,
        ?Sleeper $sleeper = null,
        ?LoggerInterface $logger = null,
        ?callable $errors = null,
    ) {
        $this->retry = $retry ?? new RetryPolicy;
        $this->sleeper = $sleeper ?? new SystemSleeper;
        $this->logger = $logger ?? new NullLogger;
        $this->errors = $errors !== null ? Closure::fromCallable($errors) : null;
    }

    /**
     * A copy that reads error responses with $errors first (see the
     * constructor). The copy counts its own attempts.
     *
     * @param  callable(ResponseInterface, string): ?ProviderException  $errors
     */
    public function withErrors(callable $errors): self
    {
        return new self($this->http, $this->provider, $this->retry, $this->sleeper, $this->logger, $errors);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** anthropic → Anthropic, for messages. */
    public function label(): string
    {
        return Providers::LABELS[$this->provider] ?? $this->provider;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /** How many tries the last call took. */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * POST a JSON body and decode the JSON reply. Every string in the body
     * is scrubbed to valid UTF-8 first, since one bad byte from a site's
     * database would stop the whole request from being encoded.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function json(string $url, array $headers, array $body, int $timeout, string $agent = ''): array
    {
        try {
            $encoded = json_encode(Utf8::scrub($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->failed(new BadResponse("Ghostwriter could not encode the request to {$this->label()}: {$exception->getMessage()}", $this->provider, null, $exception), $agent);
        }

        return $this->send('POST', $url, $headers + ['content-type' => 'application/json'], $encoded, $timeout, $agent);
    }

    /**
     * GET a JSON reply, retried like any other call.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function get(string $url, array $headers, int $timeout, string $agent = ''): array
    {
        return $this->send('GET', $url, $headers, '', $timeout, $agent);
    }

    /**
     * POST a multipart form and decode the JSON reply.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function multipart(string $url, array $headers, Multipart $body, int $timeout, string $agent = ''): array
    {
        return $this->send('POST', $url, $headers + ['content-type' => $body->contentType()], $body->body(), $timeout, $agent);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function send(string $method, string $url, array $headers, string $body, int $timeout, string $agent): array
    {
        $secrets = $this->secrets($headers);
        $client = $this->http->client($timeout);
        $build = $this->builder($method, $url, $headers, $body);
        $started = $this->retry->now();
        $budget = $timeout * $this->retry->attempts;
        $slept = 0.0;

        for ($attempt = 1; ; $attempt++) {
            $this->attempts = $attempt;
            try {
                $response = $client->sendRequest($build());
            } catch (ClientExceptionInterface $exception) {
                $message = $this->redact($exception->getMessage(), $secrets);

                if ($this->isResponseTimeout($exception)) {
                    throw $this->failed(new Unreachable("{$this->label()} took longer than {$timeout} seconds.", $this->provider, null, $exception), $agent);
                }

                $retryable = $exception instanceof NetworkExceptionInterface;
                $wait = $retryable && $attempt < $this->retry->attempts ? $this->retry->wait($attempt) : null;

                if ($wait === null || max($this->retry->now() - $started, $slept) + $wait > $budget) {
                    throw $this->failed(new Unreachable("Could not reach {$this->label()}: {$message}", $this->provider, null, $exception), $agent);
                }

                $slept += $this->pause($agent, null, $attempt, $wait);

                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $this->decode($response, $status, $agent);
            }

            $wait = null;
            $askedTooLong = false;

            if ($this->retry->retriesStatus($status) && $attempt < $this->retry->attempts) {
                $wait = $this->retry->wait($attempt, $response);
                $askedTooLong = $wait === null;
            }

            if ($wait === null || max($this->retry->now() - $started, $slept) + $wait > $budget) {
                throw $this->failed($this->error($response, $secrets, $askedTooLong ? $this->retry->asked($response) : null), $agent);
            }

            $slept += $this->pause($agent, $status, $attempt, $wait);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BadResponse when the body is not a JSON object or list.
     */
    private function decode(ResponseInterface $response, int $status, string $agent): array
    {
        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw $this->failed(new BadResponse("{$this->label()} sent back something that was not JSON.", $this->provider, $status), $agent);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Builds a fresh request for each try, since a body stream is used up
     * once sent.
     *
     * @param  array<string, string>  $headers
     * @return Closure(): RequestInterface
     */
    private function builder(string $method, string $url, array $headers, string $body): Closure
    {
        return function () use ($method, $url, $headers, $body): RequestInterface {
            $request = $this->http->requestFactory()->createRequest($method, $url);

            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            return $method === 'GET' && $body === '' ? $request : $request->withBody($this->http->streamFactory()->createStream($body));
        };
    }

    private function pause(string $agent, ?int $status, int $attempt, float $wait): float
    {
        $this->logger->warning('Ghostwriter is retrying a model call.', [
            'agent' => $agent,
            'provider' => $this->provider,
            'status' => $status,
            'attempt' => $attempt,
            'wait' => round($wait, 3),
        ]);

        $this->sleeper->sleep($wait);

        return $wait;
    }

    /**
     * @param  array<int, string>  $secrets
     * @param  float|null  $askedWait  Set when the provider asked to wait longer than the retry cap.
     */
    private function error(ResponseInterface $response, array $secrets, ?float $askedWait): ProviderException
    {
        $status = $response->getStatusCode();
        $label = $this->label();

        if ($this->errors !== null) {
            $body = (string) $response->getBody();
            $own = ($this->errors)($response, $body);

            if ($own !== null) {
                return $own;
            }

            $response = $response->withBody($this->http->streamFactory()->createStream($body));
        }

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationFailed(
                "{$label} didn't accept the API key ({$status}). Check it in Ghostwriter's Connections".(isset(Credentials::ENV[$this->provider]) ? ' (or '.Credentials::ENV[$this->provider].' in .env)' : '').'.',
                $this->provider,
                $status,
            ),
            $status === 429 => new RateLimited(
                $askedWait !== null
                    ? "{$label} is limiting requests and asked Ghostwriter to wait ".(int) ceil($askedWait).' seconds. Try again then.'
                    : "{$label} is limiting requests. Try again in a minute.",
                $this->provider,
                $status,
            ),
            $status === 503, $status === 529 => new Overloaded(
                $askedWait !== null
                    ? "{$label} is busy right now and asked Ghostwriter to wait ".(int) ceil($askedWait).' seconds. Try again then.'
                    : "{$label} is busy right now. Try again shortly.",
                $this->provider,
                $status,
            ),
            default => new BadResponse(
                $this->redact($this->explain($response), $secrets),
                $this->provider,
                $status,
                null,
                $status >= 500 || $this->retry->retriesStatus($status),
            ),
        };
    }

    /**
     * The provider's own words for what went wrong, where it gave any.
     */
    private function explain(ResponseInterface $response): string
    {
        $data = json_decode((string) $response->getBody(), true);
        $message = is_array($data) ? ($data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null)) : null;

        return sprintf('%s said no (%d)%s', $this->label(), $response->getStatusCode(), is_string($message) && $message !== '' ? ": {$message}" : '.');
    }

    private function failed(ProviderException $exception, string $agent): ProviderException
    {
        $this->logger->error('A model call failed.', [
            'agent' => $agent,
            'provider' => $this->provider,
            'exception' => $exception::class,
            'status' => $exception->status(),
            'message' => $exception->getMessage(),
        ]);

        return $exception;
    }

    private function isResponseTimeout(Throwable $exception): bool
    {
        return $exception instanceof NetworkExceptionInterface
            && (bool) preg_match('/operation timed out|bytes received/i', $exception->getMessage());
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<int, string>
     */
    private function secrets(array $headers): array
    {
        $secrets = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::SECRET_HEADERS, true)) {
                $secrets[] = $value;
                $secrets[] = (string) preg_replace('/^Bearer\s+/i', '', $value);
            }
        }

        return array_values(array_filter(array_unique($secrets), fn (string $secret) => strlen($secret) >= 4));
    }

    /**
     * @param  array<int, string>  $secrets
     */
    private function redact(string $message, array $secrets): string
    {
        return $secrets === [] ? $message : str_replace($secrets, '[redacted]', $message);
    }
}
