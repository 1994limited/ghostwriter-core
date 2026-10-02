<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;

/**
 * A library whose customer signs in to connect their own account (OAuth
 * authorization code), for libraries with Capabilities::$needsOAuth:
 * "Connect account" and "Disconnect" in the addon's settings.
 *
 * The addon owns the control panel routes; the library owns everything
 * the provider sees. The flow (docs/connecting-accounts.md):
 *
 *     // GET  …/ghostwriter/libraries/{id}/connect   (managers only)
 *     $state = random, single use, kept in the CP session with the library ID;
 *     redirect to $library->authorizationUrl($state, $redirectUri);
 *
 *     // GET  …/ghostwriter/libraries/{id}/callback?code=…&state=…
 *     check `state` against the session and drop it (else refuse);
 *     $library->connect($code, $redirectUri);   // the same $redirectUri
 *
 *     // POST …/ghostwriter/libraries/{id}/disconnect
 *     $library->disconnect();
 *
 * Tokens go through the LibraryTokens port the library was built with,
 * under its id(): connect() and refresh() put them there, disconnect()
 * forgets them. The addon never needs to touch them. Where the provider
 * supports PKCE, the library uses it, deriving the verifier from `$state`
 * and its own secret (OAuth\Pkce), so nothing else has to be kept between
 * the two requests. No method's message ever holds a code, a token or a
 * secret.
 */
interface ConnectsAccount extends PhotoLibrary
{
    /**
     * Where to send the person to sign in and allow access. `$state` is
     * the addon's random, single-use value, handed back on the callback;
     * `$redirectUri` is the callback route's absolute address, which the
     * provider must accept (each library's docs say how it is registered).
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * Exchange the callback's code for tokens, and keep them. Call it only
     * after checking the callback's `state`. `$redirectUri` and `$state`
     * are the ones authorizationUrl() was given.
     *
     * @throws NotConnected when the provider refuses the code.
     */
    public function connect(string $code, string $redirectUri, string $state = ''): TokenSet;

    /**
     * Fresh tokens for ones that have expired, and keep them. Tokens that
     * don't expire come back as they are. The library calls this itself
     * before a call that needs the account; the addon may call it from a
     * "Check connection" button.
     *
     * @throws NotConnected when they can't be refreshed: connect again.
     */
    public function refresh(TokenSet $tokens): TokenSet;

    /** Whether a connected account's tokens are kept. */
    public function connected(): bool;

    /**
     * Forget the tokens (and revoke them where the provider can). Licences
     * already bought stay in the ledger.
     */
    public function disconnect(): void;
}
