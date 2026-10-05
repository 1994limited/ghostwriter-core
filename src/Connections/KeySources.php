<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * Credentials that can say where a service's key comes from: 'env' (the
 * environment or config, which always wins), 'stored' (set up on the
 * Connections page), 'connected' (Ai\Credentials\ConnectedCredentials'
 * word for a key from Connect with OpenRouter), or null for none.
 */
interface KeySources
{
    public function source(string $service): ?string;
}
