<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Connections;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\OpenRouterConnection;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ConnectionRefused;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Mask;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Services;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Status;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Connections\StoredProviderKeys;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\InMemoryCredentialStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use PHPUnit\Framework\TestCase;

/**
 * The one resolver for every service's key: the environment first, then
 * what was set up on the Connections page.
 */
class ConnectionsTest extends TestCase
{
    private const PEXELS = 'pexels-stored-0123456789abcd';

    private ArrayCredentials $env;

    private InMemoryCredentialStore $store;

    /** @var array<int, string> Variables the fake .env file sets. */
    private array $envFile = [];

    protected function setUp(): void
    {
        $this->env = new ArrayCredentials;
        $this->store = new InMemoryCredentialStore;
    }

    private function connections(): Connections
    {
        return new Connections($this->env, $this->store, inEnvFile: fn (string $variable) => in_array($variable, $this->envFile, true), clock: fn () => new DateTimeImmutable('2026-10-05T12:00:00+00:00'));
    }

    public function test_a_key_set_up_here_is_used_when_the_environment_has_none(): void
    {
        $this->connections()->save('pexels', ['key' => '  '.self::PEXELS."\n"]);

        $this->assertSame(self::PEXELS, $this->connections()->key('pexels'));
        $this->assertSame('stored', $this->connections()->source('pexels'));
    }

    public function test_the_environment_wins_over_a_key_set_up_here(): void
    {
        $this->connections()->save('pexels', ['key' => self::PEXELS]);
        $this->env->set('pexels', 'pexels-from-env-0123456789');
        $this->envFile = ['PEXELS_API_KEY'];

        $this->assertSame('pexels-from-env-0123456789', $this->connections()->key('pexels'));
        $this->assertSame('env', $this->connections()->source('pexels'));

        $status = $this->connections()->status('pexels');
        $this->assertSame(Status::ENV, $status->state);
        $this->assertSame('env', $status->where);
        $this->assertNull($status->ending, 'An environment key is never shown, not even its ending.');
        $this->assertSame('Set in .env', $status->label());
    }

    public function test_a_key_from_a_config_file_says_so(): void
    {
        $this->env->set('anthropic', 'sk-ant-config-0123456789');

        $status = $this->connections()->status('anthropic');

        $this->assertSame(Status::ENV, $status->state);
        $this->assertSame('config', $status->where);
        $this->assertSame('Set in config', $status->label());
    }

    public function test_with_the_environment_setting_the_key_every_field_comes_from_there(): void
    {
        $this->connections()->save('shutterstock', ['key' => 'stored-key-0123456789', 'secret' => 'stored-secret-0123456789']);
        $this->env->set('shutterstock', 'env-key-0123456789');

        $this->assertSame('env-key-0123456789', $this->connections()->key('shutterstock'));
        $this->assertNull($this->connections()->key('shutterstock_secret'), 'A stored secret is never mixed with a key from the environment.');
    }

    public function test_a_key_and_secret_are_kept_and_read_by_their_handles(): void
    {
        $this->connections()->save('shutterstock', ['key' => 'consumer-key-0123456789', 'secret' => 'consumer-secret-0123456789']);

        $this->assertSame('consumer-key-0123456789', $this->connections()->key('shutterstock'));
        $this->assertSame('consumer-secret-0123456789', $this->connections()->key('shutterstock_secret'));
        $this->assertSame('••6789', $this->connections()->status('shutterstock')->ending);
    }

    public function test_saving_refuses_a_missing_field_and_an_environment_key(): void
    {
        try {
            $this->connections()->save('shutterstock', ['key' => 'consumer-key-0123456789', 'secret' => ' ']);
            $this->fail('A missing secret is refused.');
        } catch (ConnectionRefused $refused) {
            $this->assertSame('check.missing', $refused->key);
            $this->assertSame('Paste the Secret first.', $refused->getMessage());
        }

        $this->env->set('pexels', 'pexels-from-env-0123456789');

        try {
            $this->connections()->save('pexels', ['key' => self::PEXELS]);
            $this->fail('A service the environment sets is refused.');
        } catch (ConnectionRefused $refused) {
            $this->assertSame('env-wins', $refused->key);
            $this->assertStringContainsString('PEXELS_API_KEY', $refused->getMessage());
            $this->assertSame([], $this->store->values, 'Nothing was kept.');
        }

        $this->expectException(ConnectionRefused::class);
        $this->connections()->forget('pexels');
    }

    public function test_status_shows_only_the_last_four_characters(): void
    {
        $status = $this->connections()->save('pexels', ['key' => self::PEXELS]);

        $this->assertSame(Status::CONNECTED, $status->state);
        $this->assertSame('••abcd', $status->ending);
        $this->assertSame('Connected · key ending ••abcd', $status->label());
        $this->assertSame('2026-10-05T12:00:00+00:00', $status->savedAt);
        $this->assertSame('paste', $status->via);
        $this->assertStringNotContainsString(self::PEXELS, json_encode($status->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_masking(): void
    {
        $this->assertSame('••wxyz', Mask::ending('sk-ant-abcdefghijklmnopqrstuvwxyz'));
        $this->assertSame('••', Mask::ending('short-key'), 'A short key gives nothing away.');
        $this->assertSame(Mask::fingerprint('abc'), Mask::fingerprint(' abc '));
        $this->assertNotSame(Mask::fingerprint('abc'), Mask::fingerprint('abd'));
    }

    public function test_not_set_up_no_key_needed_and_unknown(): void
    {
        $this->assertSame(Status::NOT_SET, $this->connections()->status('unsplash')->state);
        $this->assertSame('Not set up', $this->connections()->status('unsplash')->label());
        $this->assertSame(Status::NO_KEY, $this->connections()->status('openverse')->state);
        $this->assertTrue($this->connections()->status('openverse')->usable());
        $this->assertNull($this->connections()->key('unsplash'));

        $this->expectException(ConnectionRefused::class);
        $this->connections()->status('nope');
    }

    public function test_a_handle_it_does_not_know_goes_to_the_environment(): void
    {
        $this->env->set('getty', 'getty-key-0123456789');

        $this->assertSame('getty-key-0123456789', $this->connections()->key('getty'));
    }

    public function test_disconnecting_forgets_the_key_and_any_note(): void
    {
        $this->connections()->save('pexels', ['key' => self::PEXELS]);
        $this->connections()->markBroken('pexels');
        $this->connections()->forget('pexels');

        $this->assertNull($this->connections()->key('pexels'));
        $this->assertSame([], $this->store->values);
    }

    public function test_a_refused_key_is_noted_until_it_is_replaced_or_works_again(): void
    {
        $this->connections()->save('pexels', ['key' => self::PEXELS]);
        $this->connections()->markBroken('pexels');

        $status = $this->connections()->status('pexels');
        $this->assertSame(Status::BROKEN, $status->state);
        $this->assertSame('Key stopped working', $status->label());
        $this->assertSame('••abcd', $status->ending);
        $this->assertNull($this->connections()->key('pexels') === null ? 'missing' : null, 'A broken key is still offered, in case it was a blip.');

        $this->connections()->markWorking('pexels');
        $this->assertSame(Status::CONNECTED, $this->connections()->status('pexels')->state);

        $this->connections()->markBroken('pexels');
        $this->connections()->save('pexels', ['key' => 'pexels-new-key-0123456789']);
        $this->assertSame(Status::CONNECTED, $this->connections()->status('pexels')->state, 'A new key clears the note.');
    }

    public function test_a_note_is_about_the_key_it_was_made_for(): void
    {
        $this->env->set('anthropic', 'sk-ant-first-0123456789');
        $this->connections()->markBroken('anthropic');
        $this->assertTrue($this->connections()->status('anthropic')->broken);
        $this->assertSame(Status::ENV, $this->connections()->status('anthropic')->state);

        $this->env->set('anthropic', 'sk-ant-second-0123456789');
        $this->assertFalse($this->connections()->status('anthropic')->broken, 'Changing the key in .env clears it.');
    }

    public function test_keys_kept_somewhere_older_are_adopted_once(): void
    {
        $this->assertTrue($this->connections()->adopt('openrouter', ['key' => 'sk-or-v1-old-0123456789']));
        $this->assertFalse($this->connections()->adopt('openrouter', ['key' => 'sk-or-v1-other-0123456789']), 'What is kept already stays.');
        $this->assertFalse($this->connections()->adopt('shutterstock', ['key' => 'only-a-key-0123456789']), 'Incomplete fields are not adopted.');

        $this->assertSame('sk-or-v1-old-0123456789', $this->connections()->key('openrouter'));
        $this->assertSame('migrated', $this->connections()->status('openrouter')->via);
    }

    public function test_connect_with_openrouter_keeps_its_key_on_the_same_card(): void
    {
        $connections = $this->connections();
        $keys = new StoredProviderKeys($connections);
        $connection = new OpenRouterConnection($this->env, $keys, new MockHttpClient);

        $keys->put('openrouter', 'sk-or-v1-connected-0123456789');

        $this->assertTrue($connection->connected());
        $this->assertSame('sk-or-v1-connected-0123456789', $connections->key('openrouter'));
        $this->assertSame('connect', $connections->status('openrouter')->via);

        $connection->disconnect();
        $this->assertNull($connections->key('openrouter'));
    }

    public function test_providers_write_with_a_key_set_up_here(): void
    {
        $this->connections()->save('anthropic', ['key' => 'sk-ant-stored-0123456789']);
        $providers = new Providers($this->connections(), new MockHttpClient, new StaticProviderSettings('anthropic'));

        $this->assertTrue($providers->configured());
        $this->assertSame(['ANTHROPIC_API_KEY' => true, 'OPENAI_API_KEY' => false, 'GEMINI_API_KEY' => false, 'UNSPLASH_ACCESS_KEY' => false, 'PIXABAY_API_KEY' => false, 'PEXELS_API_KEY' => false, 'OPENROUTER_API_KEY' => false], $providers->keyStatus());
    }

    public function test_library_tokens_are_kept_beside_the_keys(): void
    {
        $tokens = new StoredLibraryTokens($this->store);
        $tokens->put('shutterstock', new TokenSet('access-0123456789', refreshToken: 'refresh-0123'));

        $this->assertSame('access-0123456789', $tokens->get('shutterstock')?->accessToken);
        $this->assertArrayHasKey('tokens:shutterstock', $this->store->values);

        $tokens->forget('shutterstock');
        $this->assertNull($tokens->get('shutterstock'));
    }

    public function test_the_test_services_are_only_there_when_asked_for(): void
    {
        $this->assertFalse(Services::all()->has(Services::TEST_ENV));

        $connections = new Connections($this->env, $this->store, Services::all()->withTestServices());
        putenv('GHOSTWRITER_E2E_ENV_KEY=dummy-not-a-real-key-0000');

        try {
            $this->assertSame(Status::ENV, $connections->status(Services::TEST_ENV)->state);
            $this->assertSame(Status::NOT_SET, $connections->status(Services::TEST_PASTE)->state);
            $this->assertSame('test', array_key_last($connections->services()->grouped()));
        } finally {
            putenv('GHOSTWRITER_E2E_ENV_KEY');
        }
    }

    public function test_every_service_has_its_words_in_every_language(): void
    {
        $english = Strings::english();

        foreach (Services::all()->withTestServices()->list() as $service) {
            $this->assertTrue($english->has("{$service->id}.about"), "{$service->id}.about");

            for ($i = 1; $i <= $service->steps; $i++) {
                $this->assertTrue($english->has("{$service->id}.step.{$i}"), "{$service->id}.step.{$i}");
            }
        }

        foreach (Strings::TRANSLATED as $language) {
            $translated = require Strings::path($language);

            foreach ($translated as $key => $text) {
                $this->assertTrue($english->has($key), "{$language}: {$key} is not an English key.");
                preg_match_all('/:([a-z_]+)/', $english->get($key), $want);
                preg_match_all('/:([a-z_]+)/', $text, $have);
                sort($want[1]);
                sort($have[1]);
                $this->assertSame($want[1], $have[1], "{$language}: {$key} keeps the same parameters.");
            }

            foreach (array_keys($english->all()) as $key) {
                if (! str_starts_with($key, 'e2e-')) {
                    $this->assertArrayHasKey($key, $translated, "{$language} translates {$key}.");
                }
            }
        }

        $this->assertSame('Verbunden · Schlüssel endet auf ••abcd', Strings::for('de_DE')->get('status.connected', ['ending' => '••abcd']));
        $this->assertSame('Connected · key ending ••abcd', Strings::for('pt')->get('status.connected', ['ending' => '••abcd']));
    }
}
