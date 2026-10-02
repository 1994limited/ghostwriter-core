<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

use Psr\Http\Client\ClientInterface;

/**
 * Clients for fetching from addresses core doesn't choose, such as photo
 * library files. An HttpClients may implement this as well; core's photo
 * search uses it when it does.
 *
 * A download client must hand back 3xx responses rather than follow them,
 * so core can check every hop is https before following it. With $stream
 * it should also hand back the body as it arrives, so core can stop reading
 * at its size cap instead of downloading the whole file first.
 *
 * Http\GuzzleHttpClients and Testing\MockHttpClient implement it. An
 * HttpClients that doesn't is still used, but its clients may follow
 * redirects on their own, out of core's sight.
 */
interface DownloadClients
{
    /** A PSR-18 client that never follows redirects, giving up after $timeout seconds. */
    public function downloadClient(int $timeout, bool $stream = true): ClientInterface;
}
