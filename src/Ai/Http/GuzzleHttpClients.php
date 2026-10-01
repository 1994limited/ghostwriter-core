<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * HTTP clients built on Guzzle 7, which implements PSR-18 and, through
 * HttpFactory, PSR-17. Only usable when guzzlehttp/guzzle is installed;
 * core suggests it rather than requiring it.
 *
 *     new GuzzleHttpClients();                          // plain clients
 *     new GuzzleHttpClients(['handler' => $stack]);     // e.g. a mock handler in tests
 *
 * The timeouts always come from core; other Guzzle options are passed through.
 */
final class GuzzleHttpClients implements HttpClients
{
    private ?HttpFactory $factory = null;

    /**
     * @param  array<string, mixed>  $options  Guzzle client options.
     *
     * @throws LogicException when Guzzle is not installed.
     */
    public function __construct(
        private readonly array $options = [],
        private readonly int $connectTimeout = 15,
    ) {
        if (! class_exists(Client::class)) {
            throw new LogicException('GuzzleHttpClients needs guzzlehttp/guzzle. Run: composer require guzzlehttp/guzzle');
        }
    }

    public function client(int $timeout): ClientInterface
    {
        return new Client(['timeout' => $timeout, 'connect_timeout' => $this->connectTimeout] + $this->options);
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->factory ??= new HttpFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->factory ??= new HttpFactory;
    }
}
