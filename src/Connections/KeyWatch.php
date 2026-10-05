<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\DownloadClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * "Key stopped working", found on use: the site's HttpClients, watched.
 * When a service in Services::HOSTS refuses a call with a key (401; a 400
 * or 403 that says so, for Pixabay and Gemini), the resolver notes it
 * (Connections::markBroken) and the card says so; the next call it accepts
 * clears the note. Calls to anywhere else, and downloads, pass untouched.
 *
 *     $http = new KeyWatch(new GuzzleHttpClients, $connections);
 *
 * Watching never gets in a call's way: a store that fails is ignored.
 */
final class KeyWatch implements DownloadClients, HttpClients
{
    /**
     * @param  array<string, string>  $hosts  Service by API host.
     */
    public function __construct(
        private readonly HttpClients $inner,
        private readonly Connections $connections,
        private readonly array $hosts = Services::HOSTS,
    ) {}

    public function client(int $timeout): ClientInterface
    {
        return new WatchedClient($this->inner->client($timeout), $this->connections, $this->hosts, $this->inner->streamFactory());
    }

    /** Downloads fetch photo files from wherever they are, with no key: not watched. */
    public function downloadClient(int $timeout, bool $stream = true): ClientInterface
    {
        return $this->inner instanceof DownloadClients ? $this->inner->downloadClient($timeout, $stream) : $this->inner->client($timeout);
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->inner->requestFactory();
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->inner->streamFactory();
    }

    public function inner(): HttpClients
    {
        return $this->inner;
    }
}
