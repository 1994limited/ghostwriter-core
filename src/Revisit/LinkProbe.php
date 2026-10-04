<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

/**
 * Asks another site whether a page is still there, for the opt-in weekly
 * check of external links. One request at a time, never following more
 * than a few redirects, giving up after $timeout seconds: a HEAD request,
 * and a GET of the first bytes when the site refuses HEAD (405, 501).
 *
 * Core's HttpLinkProbe does this over any PSR-18 client (Ai\Ports\
 * HttpClients); an addon may use its framework's HTTP client instead and
 * prove it with Tests\Contracts\LinkProbeContract. Politeness (one
 * request per host per second, a cap per run) is ExternalLinkCheck's, not
 * the probe's.
 */
interface LinkProbe
{
    public function probe(string $url, int $timeout): LinkResult;
}
