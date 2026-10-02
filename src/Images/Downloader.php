<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Promise\Utils as Promises;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\DownloadClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches from photo libraries and the addresses they hand out, carefully:
 *
 * - https only, on every hop. Redirects are followed here, at most three,
 *   and each new address is checked before it is fetched. Headers (an API
 *   key) are dropped when a redirect leaves the host they were sent to.
 * - Never to localhost, a private or reserved IP address, or a .local or
 *   .internal name.
 * - Bodies are read up to a cap and no further; a declared length over the
 *   cap is refused before reading.
 * - Images must say they are images and be a JPEG, PNG or WebP that PHP can
 *   read; the type is taken from the bytes, not the header.
 * - Failures become PhotoUnavailable with a message that names the host at
 *   most, never an address with a key in it.
 *
 * Thumbnails are fetched side by side when the client is Guzzle, one after
 * another otherwise.
 *
 * @internal Used by StockSearch; not part of core's public API.
 */
final class Downloader
{
    public const MAX_REDIRECTS = 3;

    /** The image types a photo may be. */
    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** The most JSON a library may answer with. */
    private const MAX_JSON_BYTES = 5 * 1024 * 1024;

    private const REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(private readonly HttpClients $http) {}

    /**
     * A library's JSON answer.
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     * @return array<mixed>
     *
     * @throws PhotoUnavailable
     */
    public function json(string $url, array $query = [], array $headers = [], int $timeout = 20, string $label = 'The photo library'): array
    {
        $url .= $query === [] ? '' : (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = $this->get($this->client($timeout, false), $url, $headers + ['Accept' => 'application/json']);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $this->discard($response);

            throw new PhotoUnavailable(match (true) {
                $status === 401 || $status === 403 => "{$label} refused the key. Check it in the settings.",
                $status === 404 => 'That photograph could not be found.',
                $status === 429 => "{$label} has had too many searches. Try again in a minute.",
                default => "{$label} answered with an error ({$status}).",
            });
        }

        $data = json_decode($this->read($response, self::MAX_JSON_BYTES), true);

        return is_array($data) ? $data : [];
    }

    /**
     * One image, streamed and cut off at $maxBytes.
     *
     * @param  array<string, string>  $headers
     * @return array{content: string, mime: string}
     *
     * @throws PhotoUnavailable
     */
    public function image(string $url, int $maxBytes, int $timeout = 60, array $headers = []): array
    {
        return $this->checkedImage($this->get($this->client($timeout, true), $url, $headers), $maxBytes);
    }

    /**
     * Several images, such as thumbnails. Each that can't be fetched, or
     * isn't an image under $maxBytes, comes back null.
     *
     * @template K of array-key
     *
     * @param  array<K, string>  $urls
     * @return array<K, array{content: string, mime: string}|null>
     */
    public function images(array $urls, int $maxBytes, int $timeout = 15): array
    {
        $client = $this->client($timeout, false);
        $results = array_map(fn () => null, $urls);
        $safe = [];

        foreach ($urls as $key => $url) {
            if ($this->secure($url)) {
                $safe[$key] = $url;
            }
        }

        foreach ($this->sendAll($client, $safe) as $key => $response) {
            if ($response instanceof Throwable) {
                continue;
            }

            try {
                $results[$key] = $this->checkedImage($this->follow($client, $response, $safe[$key], [], 0), $maxBytes);
            } catch (PhotoUnavailable) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    /**
     * A request whose answer doesn't matter, such as Unsplash's download
     * count. Failures are ignored.
     *
     * @param  array<string, string>  $headers
     */
    public function ping(string $url, array $headers = [], int $timeout = 10): void
    {
        try {
            $this->discard($this->get($this->client($timeout, false), $url, $headers));
        } catch (Throwable) {
            // Nothing depends on it.
        }
    }

    /** Whether core would fetch from this address: https, and not a local or private host. */
    public function secure(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|internal)\.?$/', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws PhotoUnavailable
     */
    private function get(ClientInterface $client, string $url, array $headers): ResponseInterface
    {
        return $this->follow($client, $this->send($client, $url, $headers), $url, $headers, 0);
    }

    /**
     * Follow a redirect answer, https only, until a response that isn't one.
     *
     * @param  array<string, string>  $headers
     *
     * @throws PhotoUnavailable
     */
    private function follow(ClientInterface $client, ResponseInterface $response, string $url, array $headers, int $hops): ResponseInterface
    {
        while (in_array($response->getStatusCode(), self::REDIRECTS, true)) {
            $this->discard($response);

            if (++$hops > self::MAX_REDIRECTS) {
                throw new PhotoUnavailable('That photograph moved too many times to follow.');
            }

            $location = trim($response->getHeaderLine('Location'));

            if ($location === '') {
                throw new PhotoUnavailable('That photograph moved without saying where.');
            }

            $next = $this->resolve($url, $location);

            // A key is for the host it was given to, not wherever it points.
            if ($this->host($next) !== $this->host($url)) {
                $headers = [];
            }

            $url = $next;
            $response = $this->send($client, $url, $headers);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws PhotoUnavailable
     */
    private function send(ClientInterface $client, string $url, array $headers): ResponseInterface
    {
        if (! $this->secure($url)) {
            throw new PhotoUnavailable('That photograph has no secure download address.');
        }

        $request = $this->http->requestFactory()->createRequest('GET', $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            return $client->sendRequest($request);
        } catch (ClientExceptionInterface) {
            // The exception's message may hold the address, and the address a key.
            throw new PhotoUnavailable("Could not reach {$this->host($url)}. Try again in a moment.");
        }
    }

    /**
     * @template K of array-key
     *
     * @param  array<K, string>  $urls
     * @return array<K, ResponseInterface|Throwable>
     */
    private function sendAll(ClientInterface $client, array $urls): array
    {
        $factory = $this->http->requestFactory();

        if (interface_exists(GuzzleClient::class) && class_exists(Promises::class) && $client instanceof GuzzleClient && count($urls) > 1) {
            $promises = array_map(fn (string $url) => $client->sendAsync($factory->createRequest('GET', $url), ['http_errors' => false]), $urls);
            $results = [];

            foreach (Promises::settle($promises)->wait() as $key => $outcome) {
                $value = $outcome['value'] ?? null;
                $results[$key] = $value instanceof ResponseInterface ? $value : new PhotoUnavailable('That thumbnail could not be fetched.');
            }

            return $results;
        }

        $results = [];

        foreach ($urls as $key => $url) {
            try {
                $results[$key] = $this->send($client, $url, []);
            } catch (Throwable $exception) {
                $results[$key] = $exception;
            }
        }

        return $results;
    }

    /**
     * @return array{content: string, mime: string}
     *
     * @throws PhotoUnavailable
     */
    private function checkedImage(ResponseInterface $response, int $maxBytes): array
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $this->discard($response);

            throw new PhotoUnavailable("That photograph could not be downloaded ({$status}).");
        }

        $declared = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if (! str_starts_with($declared, 'image/')) {
            $this->discard($response);

            throw new PhotoUnavailable('That file is not an image Ghostwriter can use.');
        }

        $content = $this->read($response, $maxBytes);
        $size = $content === '' ? false : @getimagesizefromstring($content);

        if ($size === false || ! isset(self::IMAGE_TYPES[$size['mime']])) {
            throw new PhotoUnavailable('That file is not an image Ghostwriter can use.');
        }

        return ['content' => $content, 'mime' => $size['mime']];
    }

    /**
     * The body, read no further than $max bytes.
     *
     * @throws PhotoUnavailable when it is larger.
     */
    private function read(ResponseInterface $response, int $max): string
    {
        $length = $response->getHeaderLine('Content-Length');
        $body = $response->getBody();

        if (ctype_digit($length) && (int) $length > $max) {
            $body->close();

            throw new PhotoUnavailable('That photograph is too large to use.');
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $content = '';

        while (! $body->eof()) {
            $chunk = $body->read(min(65536, $max + 1 - strlen($content)));

            if ($chunk === '') {
                break;
            }

            $content .= $chunk;

            if (strlen($content) > $max) {
                $body->close();

                throw new PhotoUnavailable('That photograph is too large to use.');
            }
        }

        $body->close();

        return $content;
    }

    private function discard(ResponseInterface $response): void
    {
        try {
            $response->getBody()->close();
        } catch (Throwable) {
            // Already closed.
        }
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base) ?: [];
        $origin = 'https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function client(int $timeout, bool $stream): ClientInterface
    {
        return $this->http instanceof DownloadClients ? $this->http->downloadClient($timeout, $stream) : $this->http->client($timeout);
    }
}
