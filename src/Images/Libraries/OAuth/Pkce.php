<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth;

use InvalidArgumentException;

/**
 * PKCE (RFC 7636) for a library's "Connect account", without keeping the
 * verifier anywhere: it is derived from the addon's random, single-use
 * `state` and the library's own secret. The callback hands the state back,
 * so the library can derive the same verifier to exchange the code, while
 * someone who sees the state and the code in the address still can't
 * without the secret.
 *
 *     $challenge = Pkce::challenge(Pkce::verifier($state, $secret));   // to authorize, S256
 *     $verifier = Pkce::verifier($state, $secret);                     // to exchange the code
 */
final class Pkce
{
    public const METHOD = 'S256';

    /**
     * 43 characters of base64url: the shortest verifier RFC 7636 allows,
     * holding 256 bits.
     */
    public static function verifier(string $state, string $secret): string
    {
        if (trim($state) === '' || $secret === '') {
            throw new InvalidArgumentException('A PKCE verifier needs the state and the library\'s secret.');
        }

        return self::base64url(hash_hmac('sha256', 'ghostwriter-pkce:'.$state, $secret, true));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
