<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * KeyWatch's client: sends the request, then tells the resolver whether
 * the service accepted the key.
 *
 * @internal
 */
final class WatchedClient implements ClientInterface
{
    /**
     * @param  array<string, string>  $hosts
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Connections $connections,
        private readonly array $hosts,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $service = $this->hosts[strtolower($request->getUri()->getHost())] ?? null;

        if ($service === null) {
            return $response;
        }

        $status = $response->getStatusCode();

        try {
            if ($status >= 200 && $status < 300) {
                $this->connections->markWorking($service);
            } elseif ($status === 401) {
                $this->connections->markBroken($service);
            } elseif (($status === 400 && $service === 'pixabay') || (in_array($status, [400, 403], true) && $service === 'gemini')) {
                $body = (string) $response->getBody();
                $response = $response->withBody($this->streams->createStream($body));

                if (preg_match('/api[ _-]?key/i', $body)) {
                    $this->connections->markBroken($service);
                }
            }
        } catch (Throwable) {
            // Watching never fails a call.
        }

        return $response;
    }
}
