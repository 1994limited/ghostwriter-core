<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

/**
 * Checks a gateway address set in place of a provider's own. It must be
 * https://, except for localhost, 127.0.0.1 and [::1], which may use
 * http:// for a gateway on the same machine. It is used as given; core only
 * adds the path.
 */
final class BaseUrl
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * @return string|null The address without a trailing slash, or null when none is set.
     *
     * @throws NotConfigured when the address is not one core will send a key to.
     */
    public static function check(string $provider, ?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $label = Providers::LABELS[$provider] ?? $provider;

        $allowed = $host !== '' && ! isset($parts['user']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && ($scheme === 'https' || ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)));

        if (! $allowed) {
            throw new NotConfigured("The base URL for {$label} must be an https:// address (http:// is allowed only for localhost), with no query string or credentials.", $provider);
        }

        return rtrim($url, '/');
    }
}
