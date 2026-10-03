<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ConnectFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use SensitiveParameter;

/**
 * A model provider a site can sign in to instead of pasting a key
 * ("Connect with OpenRouter"): OAuth with PKCE (S256) that ends in an API
 * key, kept through the Ports\ProviderKeys the connection was built with.
 * A key in the environment always wins over a connected one.
 *
 * The addon owns the control panel routes; the connection owns everything
 * the provider sees. The flow (docs/connecting-accounts.md):
 *
 *     // GET  …/ghostwriter/providers/openrouter/connect   (managers only)
 *     $state = bin2hex(random_bytes(16));  $verifier = Pkce::verifier();
 *     keep both in the CP session, single use;
 *     redirect to $connection->authorizationUrl($state, $callbackUrl, Pkce::challenge($verifier));
 *
 *     // GET  …/ghostwriter/providers/openrouter/callback?code=…&state=…
 *     pull both from the session; check `state` with hash_equals() (else refuse);
 *     $connection->connect($code, $verifier);
 *
 *     // POST …/ghostwriter/providers/openrouter/disconnect
 *     $connection->disconnect();
 *
 * No method's message ever holds a code, a verifier or a key.
 */
interface ConnectsProvider
{
    /** The provider's handle, as Providers knows it: openrouter. */
    public function provider(): string;

    /**
     * Where to send the person to sign in and allow access.
     *
     * @param  string  $state  The addon's random, single-use value, handed back on the callback.
     * @param  string  $redirectUri  The callback route's absolute address (each provider's rules are in the docs).
     * @param  string  $codeChallenge  Pkce::challenge() of a verifier the addon keeps until the callback.
     *
     * @throws ConnectFailed for a callback address the provider won't accept, or while the environment holds a key.
     */
    public function authorizationUrl(string $state, string $redirectUri, string $codeChallenge): string;

    /**
     * Exchange the callback's code for a key, and keep it. Call it only
     * after checking the callback's `state`.
     *
     * @throws ConnectFailed when the provider refuses the code (expired, used, or the wrong verifier).
     * @throws ProviderException when the provider can't be reached.
     */
    public function connect(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): ConnectedKey;

    /** Whether a connected key is kept (whether or not the environment's wins). */
    public function connected(): bool;

    /** Whether the environment holds a key, which is used instead of any connected one. */
    public function usesEnvKey(): bool;

    /**
     * Forget the connected key. The provider may still hold it: the docs
     * say where the person can delete it.
     *
     * @throws ConnectFailed while the environment holds a key.
     */
    public function disconnect(): void;

    /**
     * The account behind the key in use, for "Check connection": its
     * credit limit and what is left.
     *
     * @throws NotConfigured when there is no key.
     * @throws ProviderException when the key is refused or the provider can't be reached.
     */
    public function account(): ProviderAccount;
}
