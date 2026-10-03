<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

/**
 * PKCE (RFC 7636, S256) for connecting a provider account. The addon makes
 * a verifier, keeps it in the CP session beside the state until the
 * callback, and hands the challenge to authorizationUrl():
 *
 *     $verifier = Pkce::verifier();
 *     $url = $connection->authorizationUrl($state, $callbackUrl, Pkce::challenge($verifier));
 *     // …callback…
 *     $connection->connect($code, $verifier);
 */
final class Pkce
{
    public const METHOD = 'S256';

    /** 64 characters of base64url from 48 random bytes (RFC 7636 allows 43 to 128). */
    public static function verifier(): string
    {
        return self::base64url(random_bytes(48));
    }

    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    /** Whether $value has the shape of a verifier or an S256 challenge: 43 to 128 unreserved characters. */
    public static function wellFormed(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $value);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
