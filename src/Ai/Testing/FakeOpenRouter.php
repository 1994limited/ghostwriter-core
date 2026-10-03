<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectedKey;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ProviderAccount;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ConnectFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use SensitiveParameter;

/**
 * "Connect with OpenRouter" without OpenRouter, for addon tests and demos.
 * authorizationUrl() returns the callback itself with a `code` and the
 * `state`, as if the person had allowed access, so the whole flow runs in
 * a browser. Like OpenRouter, a code works once and only with the verifier
 * whose challenge it was made for, and the callback must be https:// or
 * http://localhost.
 *
 *     $keys = new InMemoryProviderKeys;
 *     $connection = new FakeOpenRouter($keys);
 *     $this->get('/cp/ghostwriter/providers/openrouter/connect');  // → …/callback?code=…&state=…
 *
 * $refuseCode scripts a refused sign-in; $refuseKey a key OpenRouter no
 * longer accepts (account() throws AuthenticationFailed).
 */
final class FakeOpenRouter implements ConnectsProvider
{
    public const KEY = 'sk-or-v1-fake0000000000000000000000000000000000000000000000000000000000';

    /** @var array<string, string> Codes not yet used, with their challenges. */
    private array $codes = [];

    private readonly Credentials $environment;

    public function __construct(
        private readonly ProviderKeys $keys = new InMemoryProviderKeys,
        ?Credentials $environment = null,
        public bool $refuseCode = false,
        public bool $refuseKey = false,
        public ?float $limit = 10.0,
        public ?float $remaining = 7.5,
        public float $usage = 2.5,
    ) {
        $this->environment = $environment ?? new ArrayCredentials;
    }

    public function provider(): string
    {
        return 'openrouter';
    }

    public function authorizationUrl(string $state, string $redirectUri, string $codeChallenge): string
    {
        $this->refuseWithEnvKey();
        OpenRouterConnection::checkCallback($redirectUri);

        if (trim($state) === '' || ! Pkce::wellFormed($codeChallenge)) {
            throw new ConnectFailed('Connecting needs a state and an S256 code challenge.', 'openrouter');
        }

        $code = bin2hex(random_bytes(12));
        $this->codes[$code] = $codeChallenge;

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query(['code' => $code, 'state' => $state]);
    }

    public function connect(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): ConnectedKey
    {
        $this->refuseWithEnvKey();

        $challenge = $this->codes[$code] ?? null;
        unset($this->codes[$code]);

        if ($this->refuseCode || $challenge === null || ! hash_equals($challenge, Pkce::challenge($verifier))) {
            throw new ConnectFailed(OpenRouterConnection::SIGN_IN_REFUSED, 'openrouter', 403);
        }

        $this->keys->put('openrouter', self::KEY);

        return new ConnectedKey('openrouter', self::KEY);
    }

    public function connected(): bool
    {
        return trim((string) $this->keys->get('openrouter')) !== '';
    }

    public function usesEnvKey(): bool
    {
        return $this->environment->key('openrouter') !== null;
    }

    public function disconnect(): void
    {
        $this->refuseWithEnvKey();

        $this->keys->forget('openrouter');
    }

    public function account(): ProviderAccount
    {
        if (! $this->usesEnvKey() && ! $this->connected()) {
            throw new NotConfigured('OpenRouter isn\'t connected. Connect with OpenRouter in the settings, or add OPENROUTER_API_KEY to your .env file.', 'openrouter');
        }

        if ($this->refuseKey) {
            throw new AuthenticationFailed('OpenRouter no longer accepts the key Ghostwriter was connected with (401). Connect with OpenRouter again in the settings.', 'openrouter', 401);
        }

        return new ProviderAccount('openrouter', $this->usesEnvKey() ? 'env' : 'connected', 'sk-or-v1-fak...000', $this->limit, $this->remaining, $this->usage, $this->limit !== null ? 'monthly' : null);
    }

    private function refuseWithEnvKey(): void
    {
        if ($this->usesEnvKey()) {
            throw new ConnectFailed(OpenRouterConnection::ENV_KEY_SET, 'openrouter');
        }
    }
}
