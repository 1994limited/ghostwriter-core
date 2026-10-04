<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * LinkProbe over PSR-18: HEAD first, then a GET of the first byte when the
 * site refuses HEAD. 2xx and 3xx are Ok (the client follows redirects, as
 * HttpClients' clients do); 404 and 410 are Broken, and so is a name that
 * no longer resolves; anything else (401, 403, 429, 5xx, a timeout) is
 * Unknown, because it says nothing about whether the page is there.
 */
final class HttpLinkProbe implements LinkProbe
{
    public const USER_AGENT = 'Ghostwriter link check (a weekly check of links on this site\'s pages)';

    public function __construct(
        private readonly HttpClients $http,
        private readonly string $userAgent = self::USER_AGENT,
    ) {}

    public function probe(string $url, int $timeout): LinkResult
    {
        $at = gmdate(DATE_ATOM);

        try {
            $code = $this->send('HEAD', $url, $timeout);

            if (in_array($code, [405, 501, 403], true)) {
                $code = $this->send('GET', $url, $timeout);
            }
        } catch (NetworkExceptionInterface $exception) {
            $gone = preg_match('/could not resolve|name or service not known|nodename nor servname|no such host|getaddrinfo/i', $exception->getMessage()) === 1;

            return new LinkResult($url, $gone ? LinkStatus::Broken : LinkStatus::Unknown, null, $at, error: $gone ? 'dns' : 'network');
        } catch (Throwable) {
            return new LinkResult($url, LinkStatus::Unknown, null, $at, error: 'client');
        }

        return new LinkResult($url, self::statusOf($code), $code, $at);
    }

    public static function statusOf(int $code): LinkStatus
    {
        return match (true) {
            $code >= 200 && $code < 400 => LinkStatus::Ok,
            $code === 404 || $code === 410 => LinkStatus::Broken,
            default => LinkStatus::Unknown,
        };
    }

    private function send(string $method, string $url, int $timeout): int
    {
        $request = $this->http->requestFactory()->createRequest($method, $url)
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader('Accept', 'text/html,*/*;q=0.8');

        if ($method === 'GET') {
            $request = $request->withHeader('Range', 'bytes=0-0');
        }

        return $this->http->client($timeout)->sendRequest($request)->getStatusCode();
    }
}
