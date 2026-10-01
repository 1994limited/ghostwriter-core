<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Where HTTP clients come from. PSR-18 has no per-request timeout, so the
 * timeout is set when the client is built.
 *
 * The client must hand back 4xx and 5xx responses rather than throw on
 * them, as PSR-18 requires; core reads the status itself.
 *
 * Http\GuzzleHttpClients implements this when guzzlehttp/guzzle is
 * installed. Testing\MockHttpClient implements it for tests.
 */
interface HttpClients
{
    /** A PSR-18 client that gives up after $timeout seconds (connect timeout 15 s). */
    public function client(int $timeout): ClientInterface;

    public function requestFactory(): RequestFactoryInterface;

    public function streamFactory(): StreamFactoryInterface;
}
