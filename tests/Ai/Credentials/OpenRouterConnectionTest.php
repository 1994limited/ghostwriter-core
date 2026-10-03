<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Credentials;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectedCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectedKey;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ConnectFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\OutOfCredit;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeOpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\InMemoryProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;

/**
 * "Connect with OpenRouter" over a mocked network, and the fake addons
 * test their routes with.
 */
class OpenRouterConnectionTest extends ProviderTestCase
{
    private const KEY = 'sk-or-v1-abcdef0123456789abcdef0123456789';

    private const CALLBACK = 'https://cms.example.com/cp/ghostwriter/providers/openrouter/callback';

    private ArrayCredentials $env;

    private InMemoryProviderKeys $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = new ArrayCredentials;
        $this->keys = new InMemoryProviderKeys;
    }

    private function connection(string $label = 'Ghostwriter (cms.example.com)'): OpenRouterConnection
    {
        return new OpenRouterConnection($this->env, $this->keys, $this->http, $label, sleeper: $this->sleeper, retry: new RetryPolicy(random: fn (float $max) => $max));
    }

    public function test_the_sign_in_address_carries_the_callback_the_challenge_and_the_state(): void
    {
        $verifier = Pkce::verifier();
        $url = $this->connection()->authorizationUrl('state-123', self::CALLBACK, Pkce::challenge($verifier));

        $this->assertStringStartsWith('https://openrouter.ai/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'callback_url' => self::CALLBACK,
            'code_challenge' => Pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
            'state' => 'state-123',
            'key_label' => 'Ghostwriter (cms.example.com)',
        ], $query);
        $this->assertSame(64, strlen($verifier));
        $this->assertNotSame($verifier, Pkce::verifier(), 'A fresh verifier each time.');
        // RFC 7636 appendix B.
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function test_the_callback_must_be_https_or_localhost(): void
    {
        $challenge = Pkce::challenge(Pkce::verifier());

        $this->assertStringContainsString('callback_url=http%3A%2F%2Flocalhost%3A8000%2Fcb', $this->connection()->authorizationUrl('s', 'http://localhost:8000/cb', $challenge));

        foreach (['http://cms.example.com/cb', 'ftp://cms.example.com/cb', '/cp/callback', 'https://user:pw@cms.example.com/cb'] as $bad) {
            try {
                $this->connection()->authorizationUrl('s', $bad, $challenge);
                $this->fail("Expected {$bad} to be refused.");
            } catch (ConnectFailed $exception) {
                $this->assertStringContainsString('https://', $exception->getMessage());
            }
        }

        $this->expectException(ConnectFailed::class);
        $this->connection()->authorizationUrl('s', self::CALLBACK, 'too-short');
    }

    public function test_the_code_is_exchanged_once_for_a_key_that_is_kept(): void
    {
        $this->http->queueJson(['key' => self::KEY]);
        $verifier = Pkce::verifier();

        $key = $this->connection()->connect('the-code', $verifier);

        $this->assertSame(['openrouter', self::KEY], [$key->provider, $key->key()]);
        $this->assertSame('sk-or-v1-a…789', $key->masked());
        $this->assertSame(['openrouter' => self::KEY], $this->keys->keys);
        $this->assertTrue($this->connection()->connected());
        $this->assertSame('sk-or-v1-a…789', $this->connection()->maskedKey());

        $request = $this->http->requests[0];
        $this->assertSame('POST https://openrouter.ai/api/v1/auth/keys', $request->getMethod().' '.$request->getUri());
        $this->assertSame(['code' => 'the-code', 'code_verifier' => $verifier, 'code_challenge_method' => 'S256'], $this->http->body(0));
        $this->assertFalse($request->hasHeader('authorization'));
        $this->assertSame(OpenRouter::APP_URL, $request->getHeaderLine('HTTP-Referer'));

        // Never in a dump.
        $this->assertStringNotContainsString(self::KEY, print_r($key, true));
        $this->assertStringNotContainsString(self::KEY, print_r($this->keys, true));
        $this->assertStringNotContainsString(self::KEY, print_r($this->connection(), true));
    }

    public function test_a_refused_code_is_said_plainly_and_never_retried(): void
    {
        foreach ([403, 400, 409] as $status) {
            $this->http->queueJson(['error' => ['code' => $status, 'message' => 'Invalid code or code_verifier the-code']], $status);

            try {
                $this->connection()->connect('the-code', Pkce::verifier());
                $this->fail('Expected ConnectFailed.');
            } catch (ConnectFailed $exception) {
                $this->assertSame(OpenRouterConnection::SIGN_IN_REFUSED, $exception->getMessage());
                $this->assertSame($status, $exception->status());
            }
        }

        $this->assertCount(3, $this->http->requests, 'A code works once, so it is never sent twice.');
        $this->assertSame([], $this->keys->keys);
        $this->assertStringNotContainsString('the-code', (string) json_encode($this->logs));

        $this->expectException(ConnectFailed::class);
        $this->connection()->connect('', Pkce::verifier());
    }

    public function test_an_env_key_always_wins(): void
    {
        $this->keys->put('openrouter', 'sk-or-v1-connected-key-000000');
        $this->env->set('openrouter', 'sk-or-v1-env-key-0000000000000');

        $credentials = new ConnectedCredentials($this->env, $this->keys);
        $this->assertSame('sk-or-v1-env-key-0000000000000', $credentials->key('openrouter'));
        $this->assertSame('env', $credentials->source('openrouter'));

        $connection = $this->connection();
        $this->assertTrue($connection->usesEnvKey());

        foreach ([
            fn () => $connection->authorizationUrl('s', self::CALLBACK, Pkce::challenge(Pkce::verifier())),
            fn () => $connection->connect('code', Pkce::verifier()),
            fn () => $connection->disconnect(),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected ConnectFailed.');
            } catch (ConnectFailed $exception) {
                $this->assertSame(OpenRouterConnection::ENV_KEY_SET, $exception->getMessage());
            }
        }

        $this->assertSame([], $this->http->requests);

        $this->env->set('openrouter', null);
        $this->assertSame('sk-or-v1-connected-key-000000', $credentials->key('openrouter'));
        $this->assertSame('connected', $credentials->source('openrouter'));

        // Only providers that can be connected take a kept key.
        $this->keys->put('anthropic', 'kept');
        $this->assertNull($credentials->key('anthropic'));
        $this->assertNull($credentials->source('anthropic'));
    }

    public function test_disconnect_forgets_the_key(): void
    {
        $this->keys->put('openrouter', self::KEY);

        $this->connection()->disconnect();

        $this->assertFalse($this->connection()->connected());
        $this->assertNull($this->connection()->maskedKey());
    }

    public function test_check_connection_reads_the_key_limits(): void
    {
        $this->keys->put('openrouter', self::KEY);
        $this->http->queueJson(['data' => ['label' => 'sk-or-v1-abc...789', 'limit' => 100, 'limit_remaining' => 74.5, 'limit_reset' => 'monthly', 'usage' => 25.5, 'is_free_tier' => false]]);
        $this->http->queueJson(['data' => ['label' => 'sk-or-v1-abc...789', 'limit' => null, 'limit_remaining' => null, 'usage' => 3.25, 'is_free_tier' => true]]);

        $account = $this->connection()->account();

        $this->assertSame(['connected', 100.0, 74.5, 25.5, 'monthly', false], [$account->source, $account->limit, $account->remaining, $account->usage, $account->limitReset, $account->freeTier]);
        $this->assertSame('Connected. $74.50 of $100.00 left (resets monthly).', $account->summary());
        $this->assertFalse($account->exhausted());

        $request = $this->http->requests[0];
        $this->assertSame('GET https://openrouter.ai/api/v1/key', $request->getMethod().' '.$request->getUri());
        $this->assertSame('Bearer '.self::KEY, $request->getHeaderLine('Authorization'));

        $this->env->set('openrouter', 'sk-or-v1-env-key-0000000000000');
        $account = $this->connection()->account();
        $this->assertSame('Bearer sk-or-v1-env-key-0000000000000', $this->http->requests[1]->getHeaderLine('Authorization'), 'The env key wins here too.');
        $this->assertSame('Using the key from the .env file. $3.25 used; this key has no spending limit. The account has no credit yet, so only free models will answer.', $account->summary());
    }

    public function test_check_connection_says_what_is_wrong(): void
    {
        try {
            $this->connection()->account();
            $this->fail('Expected NotConfigured.');
        } catch (NotConfigured $exception) {
            $this->assertStringContainsString('Connect with OpenRouter', $exception->getMessage());
        }

        $this->keys->put('openrouter', self::KEY);
        $this->http->queueJson(['error' => ['code' => 401, 'message' => 'User not found.']], 401);
        $this->http->queueJson(['error' => ['code' => 402, 'message' => 'Insufficient credits']], 402);

        try {
            $this->connection()->account();
            $this->fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $exception) {
            $this->assertStringContainsString('Connect with OpenRouter again', $exception->getMessage());
        }

        $this->expectException(OutOfCredit::class);
        $this->connection()->account();
    }

    public function test_the_registry_writes_with_a_connected_key_and_says_how_to_connect(): void
    {
        $credentials = new ConnectedCredentials($this->env, $this->keys);
        $providers = new Providers($credentials, $this->http, new StaticProviderSettings('openrouter'), sleeper: $this->sleeper);

        $this->assertFalse($providers->configured());

        try {
            $providers->text();
            $this->fail('Expected NotConfigured.');
        } catch (NotConfigured $exception) {
            $this->assertSame('OpenRouter isn\'t connected. Connect with OpenRouter in Ghostwriter\'s settings, or add OPENROUTER_API_KEY to your .env file.', $exception->getMessage());
        }

        $this->keys->put('openrouter', self::KEY);
        $this->http->queueJson(['error' => ['code' => 401, 'message' => 'User not found.']], 401);

        $this->assertTrue($providers->configured());
        $this->assertInstanceOf(OpenRouter::class, $providers->text());
        $this->assertTrue($providers->keyStatus()['OPENROUTER_API_KEY'], 'Set, by connecting.');

        try {
            $providers->text()->text($this->request());
            $this->fail('Expected AuthenticationFailed.');
        } catch (AuthenticationFailed $exception) {
            $this->assertStringContainsString('Connect with OpenRouter again', $exception->getMessage(), 'A connected key is not in .env, so the message doesn\'t send them there.');
        }
    }

    public function test_the_fake_runs_the_whole_flow(): void
    {
        $fake = new FakeOpenRouter($this->keys, $this->env);
        $verifier = Pkce::verifier();

        $back = $fake->authorizationUrl('state-1', self::CALLBACK, Pkce::challenge($verifier));
        $this->assertStringStartsWith(self::CALLBACK.'?code=', $back);
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        $this->assertSame('state-1', $query['state']);

        // A different verifier is refused, and the code is then used up.
        foreach ([Pkce::verifier(), $verifier] as $tried) {
            try {
                $fake->connect((string) $query['code'], $tried);
                $this->fail('Expected ConnectFailed.');
            } catch (ConnectFailed $exception) {
                $this->assertSame(OpenRouterConnection::SIGN_IN_REFUSED, $exception->getMessage());
            }
        }

        $this->assertFalse($fake->connected());

        parse_str((string) parse_url($fake->authorizationUrl('state-2', self::CALLBACK, Pkce::challenge($verifier)), PHP_URL_QUERY), $again);
        $this->assertInstanceOf(ConnectedKey::class, $fake->connect((string) $again['code'], $verifier));
        $this->assertTrue($fake->connected());
        $this->assertSame('Connected. $7.50 of $10.00 left (resets monthly).', $fake->account()->summary());

        $fake->disconnect();
        $this->assertFalse($fake->connected());
    }
}
