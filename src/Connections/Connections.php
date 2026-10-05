<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use SensitiveParameter;
use Throwable;

/**
 * The keys to call every outside service with, and what the Connections
 * page sets up. One resolver for all of them:
 *
 * 1. **The environment wins.** When the environment (or config) sets a
 *    service's key, that is used, with its other fields from the
 *    environment too, and nothing kept here is read. The card says "Set in
 *    .env" and offers no set-up.
 * 2. **Then what was set up here**: a key pasted on the Connections page,
 *    or got by signing in (Connect with OpenRouter), kept encrypted
 *    through the CredentialStore port.
 * 3. Otherwise none.
 *
 * It is Ai\Ports\Credentials, so it goes wherever the environment's
 * credentials went before (Providers, StockSearch, the libraries):
 *
 *     $connections = new Connections($environmentCredentials, $store);
 *     $providers = new Providers($connections, $http, $settings, $logger);
 *     $stock = new StockSearch($http, $connections, …);
 *
 * A handle Services doesn't know is passed to the environment as it is.
 * Keys are never cached: the store is read on every call.
 */
final class Connections implements Credentials, KeySources
{
    private readonly Services $services;

    /** @var Closure(string): bool */
    private readonly Closure $inEnvFile;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  Credentials  $environment  The keys the environment and config set, alone.
     * @param  (Closure(string): bool)|null  $inEnvFile  Whether an environment variable is set in the environment (.env), as opposed to a config file; by default getenv(), $_ENV and $_SERVER are asked.
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly Credentials $environment,
        private readonly CredentialStore $store,
        ?Services $services = null,
        ?Closure $inEnvFile = null,
        ?Closure $clock = null,
    ) {
        $this->services = $services ?? Services::all();
        $this->inEnvFile = $inEnvFile ?? self::envFileCheck();
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function services(): Services
    {
        return $this->services;
    }

    /** The environment's keys alone. */
    public function environment(): Credentials
    {
        return $this->environment;
    }

    public function store(): CredentialStore
    {
        return $this->store;
    }

    public function key(string $provider): ?string
    {
        $found = $this->services->forHandle($provider);

        if ($found === null) {
            return $this->environment->key($provider);
        }

        [$service, $field] = $found;

        if ($this->fromEnvironment($service)) {
            return $this->environmentKey($service, $field);
        }

        return self::filled($this->stored($service->id)[$field->name] ?? null);
    }

    /** 'env', 'stored', or null when the service has no key. */
    public function source(string $service): ?string
    {
        $found = $this->services->get($service);

        return match (true) {
            $found === null => $this->environment->key($service) !== null ? 'env' : null,
            $this->fromEnvironment($found) => 'env',
            $this->complete($found, $this->stored($service)) => 'stored',
            default => null,
        };
    }

    /**
     * Whether the environment sets the service: any of its required fields.
     * Then all of its fields come from there.
     */
    public function fromEnvironment(Service $service): bool
    {
        foreach ($service->required() as $field) {
            if ($this->environmentKey($service, $field) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fields kept for a service, decrypted. Empty when none are, or
     * they can't be read.
     *
     * @return array<string, string>
     */
    public function stored(string $service): array
    {
        try {
            $record = $this->store->get($service);
        } catch (Throwable) {
            return [];
        }

        $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];

        return array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : '', $fields), fn (string $value) => $value !== '');
    }

    public function status(string $id): Status
    {
        $service = $this->services->get($id) ?? throw new ConnectionRefused('unknown', ['service' => $id]);

        if (! $service->needsKey()) {
            return new Status($id, Status::NO_KEY);
        }

        if ($this->fromEnvironment($service)) {
            $field = $service->required()[0];
            $key = (string) $this->environmentKey($service, $field);
            $variable = $field->env;

            return new Status($id, Status::ENV, ($this->inEnvFile)($variable) ? 'env' : 'config', broken: $this->refused($id, $key));
        }

        $record = $this->record($id);
        $fields = $this->stored($id);

        if (! $this->complete($service, $fields)) {
            return new Status($id, Status::NOT_SET);
        }

        $key = $fields[$service->required()[0]->name];
        $broken = $this->refused($id, $key);

        return new Status(
            $id,
            $broken ? Status::BROKEN : Status::CONNECTED,
            ending: Mask::ending($key),
            broken: $broken,
            savedAt: is_string($record['saved_at'] ?? null) ? $record['saved_at'] : null,
            via: is_string($record['via'] ?? null) ? $record['via'] : null,
        );
    }

    /**
     * Keep a service's fields, after the page has checked them (KeyCheck).
     * Replaces whatever was kept, and clears a note that the old key
     * stopped working.
     *
     * @param  array<string, string|null>  $fields  By field name.
     * @param  string  $via  'paste', 'connect' or 'migrated'.
     *
     * @throws ConnectionRefused while the environment sets the service, or a required field is blank.
     */
    public function save(string $id, #[SensitiveParameter] array $fields, string $via = 'paste'): Status
    {
        $service = $this->writable($id);
        $kept = [];

        foreach ($service->fields as $field) {
            $value = self::filled($fields[$field->name] ?? null);

            if ($value === null && ! $field->optional) {
                throw new ConnectionRefused('check.missing', ['field' => Strings::english()->get('field.'.$field->name)]);
            }

            if ($value !== null) {
                $kept[$field->name] = $value;
            }
        }

        $this->store->put($id, ['fields' => $kept, 'saved_at' => ($this->clock)()->format(DATE_ATOM), 'via' => $via]);
        $this->store->forget('health:'.$id);

        return $this->status($id);
    }

    /**
     * Forget what was kept for a service. Its key still works at the
     * service until it is deleted there.
     *
     * @throws ConnectionRefused while the environment sets the service.
     */
    public function forget(string $id): void
    {
        $this->writable($id);
        $this->store->forget($id);
        $this->store->forget('health:'.$id);
    }

    /**
     * Keep fields found somewhere older (a key from Connect with OpenRouter
     * in its old place), unless something is kept already. For the addons'
     * migrations; the environment doesn't matter here, as it still wins
     * when read.
     *
     * @param  array<string, string|null>  $fields
     */
    public function adopt(string $id, #[SensitiveParameter] array $fields, string $via = 'migrated'): bool
    {
        $service = $this->services->get($id);

        if ($service === null || $this->stored($id) !== []) {
            return false;
        }

        $kept = array_filter(array_map(fn ($value) => self::filled($value), $fields));

        if (! $this->complete($service, $kept)) {
            return false;
        }

        $this->store->put($id, ['fields' => $kept, 'saved_at' => ($this->clock)()->format(DATE_ATOM), 'via' => $via]);

        return true;
    }

    /**
     * Note that the key in use was refused (401), so the card says "Key
     * stopped working" until it is replaced or works again. The note is
     * about that key only: a new key clears it.
     */
    public function markBroken(string $id): void
    {
        $key = $this->key($id);

        if ($key === null) {
            return;
        }

        $this->store->put('health:'.$id, ['refused_at' => ($this->clock)()->format(DATE_ATOM), 'fingerprint' => Mask::fingerprint($key)]);
    }

    /** The key in use was accepted: drop any note that it stopped working. */
    public function markWorking(string $id): void
    {
        if ($this->store->get('health:'.$id) !== null) {
            $this->store->forget('health:'.$id);
        }
    }

    /**
     * A field as the environment sets it. The test services aren't in any
     * addon's config, so their variables are read directly.
     */
    private function environmentKey(Service $service, Field $field): ?string
    {
        $key = $this->environment->key($field->handle($service->id));

        if ($key === null && $service->group === Service::TEST) {
            $value = getenv($field->env);
            $key = self::filled(is_string($value) ? $value : ($_ENV[$field->env] ?? $_SERVER[$field->env] ?? null));
        }

        return $key;
    }

    private function refused(string $id, #[SensitiveParameter] string $key): bool
    {
        try {
            $health = $this->store->get('health:'.$id);
        } catch (Throwable) {
            return false;
        }

        return is_string($health['fingerprint'] ?? null) && hash_equals($health['fingerprint'], Mask::fingerprint($key));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $id): array
    {
        try {
            return $this->store->get($id) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function complete(Service $service, array $fields): bool
    {
        if (! $service->needsKey()) {
            return false;
        }

        foreach ($service->required() as $field) {
            if (($fields[$field->name] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    private function writable(string $id): Service
    {
        $service = $this->services->get($id);

        if ($service === null || ! $service->needsKey()) {
            throw new ConnectionRefused('unknown', ['service' => $id]);
        }

        if ($this->fromEnvironment($service)) {
            throw new ConnectionRefused('env-wins', ['service' => $service->name, 'variable' => $service->required()[0]->env]);
        }

        return $service;
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return Closure(string): bool
     */
    private static function envFileCheck(): Closure
    {
        return function (string $variable): bool {
            foreach ([getenv($variable), $_ENV[$variable] ?? null, $_SERVER[$variable] ?? null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Nothing kept reaches a dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['services' => array_keys($this->services->list())];
    }
}
