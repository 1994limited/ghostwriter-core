<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ConnectFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\BaseUrl;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * "Connect with OpenRouter": OpenRouter's OAuth PKCE flow, which ends in an
 * ordinary OpenRouter API key for the person's account, kept (encrypted, by
 * the addon) through Ports\ProviderKeys under `openrouter`.
 *
 * - Sign-in: https://openrouter.ai/auth?callback_url=…&code_challenge=…&code_challenge_method=S256&state=…
 * - Exchange: POST https://openrouter.ai/api/v1/auth/keys {code, code_verifier, code_challenge_method} → {key}.
 *   A code lasts 10 minutes and works once.
 * - Check connection: GET https://openrouter.ai/api/v1/key.
 * - The callback must be https://, or http://localhost on any port.
 *
 * OPENROUTER_API_KEY in the environment always wins: while it is set,
 * authorizationUrl(), connect() and disconnect() throw ConnectFailed, and
 * account() describes the environment's key.
 */
final class OpenRouterConnection implements ConnectsProvider
{
    public const AUTH_URL = 'https://openrouter.ai/auth';

    public const ENV_KEY_SET = 'This site uses OPENROUTER_API_KEY from its .env file, which always wins. Remove it from .env to connect with OpenRouter instead.';

    public const SIGN_IN_REFUSED = 'OpenRouter didn\'t accept the sign-in. It may have expired (it lasts 10 minutes) or been used already. Connect with OpenRouter again.';

    private readonly string $apiUrl;

    /**
     * @param  Credentials  $environment  The keys from the environment alone (not a ConnectedCredentials).
     * @param  string  $keyLabel  Prefills the key's name on OpenRouter's sign-in page, e.g. "Ghostwriter (cms.example.com)".
     * @param  string|null  $baseUrl  ProviderSettings::baseUrl('openrouter'), used for the key exchange and the account check.
     *
     * @throws NotConfigured for a base URL core won't send a key to.
     */
    public function __construct(
        private readonly Credentials $environment,
        private readonly ProviderKeys $keys,
        private readonly HttpClients $http,
        private readonly string $keyLabel = 'Ghostwriter',
        private readonly int $timeout = 30,
        ?string $baseUrl = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Sleeper $sleeper = null,
        private readonly ?RetryPolicy $retry = null,
        private readonly string $appUrl = OpenRouter::APP_URL,
        private readonly string $appTitle = OpenRouter::APP_TITLE,
    ) {
        $this->apiUrl = BaseUrl::check('openrouter', $baseUrl) ?? OpenRouter::URL;
    }

    public function provider(): string
    {
        return 'openrouter';
    }

    public function authorizationUrl(string $state, string $redirectUri, string $codeChallenge): string
    {
        $this->refuseWithEnvKey();

        if (trim($state) === '') {
            throw new ConnectFailed('Connecting needs a state value to check the callback with.', 'openrouter');
        }

        if (! Pkce::wellFormed($codeChallenge)) {
            throw new ConnectFailed('Connecting needs an S256 code challenge (Pkce::challenge()).', 'openrouter');
        }

        self::checkCallback($redirectUri);

        return self::AUTH_URL.'?'.http_build_query(array_filter([
            'callback_url' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => Pkce::METHOD,
            'state' => $state,
            'key_label' => trim($this->keyLabel),
        ], fn (string $value) => $value !== ''), '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): ConnectedKey
    {
        $this->refuseWithEnvKey();

        if (trim($code) === '' || ! Pkce::wellFormed($verifier)) {
            throw new ConnectFailed(self::SIGN_IN_REFUSED, 'openrouter');
        }

        // A code works once, so the exchange is never retried.
        $transport = $this->transport(new RetryPolicy(attempts: 1))->withErrors(
            fn (ResponseInterface $response): ?ProviderException => $response->getStatusCode() < 500
                ? new ConnectFailed(self::SIGN_IN_REFUSED, 'openrouter', $response->getStatusCode())
                : null,
        );

        $data = $transport->json($this->apiUrl.'/auth/keys', $this->headers(), [
            'code' => $code,
            'code_verifier' => $verifier,
            'code_challenge_method' => Pkce::METHOD,
        ], $this->timeout, 'connect');

        $key = $data['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            throw new BadResponse('OpenRouter did not send back a key.', 'openrouter');
        }

        $this->keys->put('openrouter', trim($key));

        return new ConnectedKey('openrouter', trim($key));
    }

    public function connected(): bool
    {
        $key = $this->keys->get('openrouter');

        return is_string($key) && trim($key) !== '';
    }

    public function usesEnvKey(): bool
    {
        return $this->environment->key('openrouter') !== null;
    }

    /** The connected key, masked, for the settings row; null when none is kept. */
    public function maskedKey(): ?string
    {
        $key = $this->keys->get('openrouter');

        return is_string($key) && trim($key) !== '' ? ConnectedKey::mask(trim($key)) : null;
    }

    public function disconnect(): void
    {
        $this->refuseWithEnvKey();

        $this->keys->forget('openrouter');
    }

    public function account(): ProviderAccount
    {
        $env = $this->environment->key('openrouter');
        $key = $env ?? ($this->connected() ? trim((string) $this->keys->get('openrouter')) : null);

        if ($key === null) {
            throw new NotConfigured('OpenRouter isn\'t connected. Connect with OpenRouter in the settings, or add OPENROUTER_API_KEY to your .env file.', 'openrouter');
        }

        $connected = $env === null;
        $data = $this->transport()->withErrors(OpenRouter::errorReader($connected))
            ->get($this->apiUrl.'/key', ['authorization' => "Bearer {$key}"] + $this->headers(), $this->timeout, 'account');

        $info = is_array($data['data'] ?? null) ? $data['data'] : null;

        if ($info === null) {
            throw new BadResponse('OpenRouter did not describe the key.', 'openrouter');
        }

        return new ProviderAccount(
            'openrouter',
            $connected ? 'connected' : 'env',
            is_string($info['label'] ?? null) ? $info['label'] : '',
            is_numeric($info['limit'] ?? null) ? (float) $info['limit'] : null,
            is_numeric($info['limit_remaining'] ?? null) ? (float) $info['limit_remaining'] : null,
            is_numeric($info['usage'] ?? null) ? (float) $info['usage'] : 0.0,
            is_string($info['limit_reset'] ?? null) && $info['limit_reset'] !== '' ? $info['limit_reset'] : null,
            (bool) ($info['is_free_tier'] ?? false),
        );
    }

    /**
     * OpenRouter's rule for the callback: https://, or http://localhost on
     * any port.
     *
     * @throws ConnectFailed for anything else.
     */
    public static function checkCallback(string $redirectUri): void
    {
        $parts = parse_url($redirectUri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        $allowed = $host !== '' && ! isset($parts['user']) && ! isset($parts['fragment'])
            && ($scheme === 'https' || ($scheme === 'http' && $host === 'localhost'));

        if (! $allowed) {
            throw new ConnectFailed('OpenRouter only sends people back to an https:// address (or http://localhost). Open the control panel over https:// to connect.', 'openrouter');
        }
    }

    /**
     * The key never reaches a message or a log line.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['provider' => 'openrouter', 'connected' => $this->connected(), 'usesEnvKey' => $this->usesEnvKey(), 'apiUrl' => $this->apiUrl];
    }

    private function refuseWithEnvKey(): void
    {
        if ($this->usesEnvKey()) {
            throw new ConnectFailed(self::ENV_KEY_SET, 'openrouter');
        }
    }

    private function transport(?RetryPolicy $retry = null): Transport
    {
        return new Transport($this->http, 'openrouter', $retry ?? $this->retry, $this->sleeper, $this->logger);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['HTTP-Referer' => $this->appUrl, 'X-OpenRouter-Title' => $this->appTitle, 'X-Title' => $this->appTitle];
    }
}
