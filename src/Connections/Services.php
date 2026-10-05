<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * Every outside service Ghostwriter uses, in the order the Connections page
 * shows them: Writing (the model providers, which also make images where
 * they can), Images (the free photo libraries) and Stock photos (the paid
 * libraries).
 *
 *     $services = Services::all();
 *     $services->get('pexels')->keyUrl;
 *
 * Getty Images and iStock join Stock photos once core has their adapter.
 */
final class Services
{
    /**
     * Where each service's API lives, for KeyWatch to tell whose key a
     * refused call was made with. A gateway set as a base URL isn't here,
     * so a refusal through one isn't put down to the key.
     */
    public const HOSTS = [
        'api.anthropic.com' => 'anthropic',
        'api.openai.com' => 'openai',
        'generativelanguage.googleapis.com' => 'gemini',
        'openrouter.ai' => 'openrouter',
        'api.unsplash.com' => 'unsplash',
        'api.pexels.com' => 'pexels',
        'pixabay.com' => 'pixabay',
        'api.shutterstock.com' => 'shutterstock',
        'api-sandbox.shutterstock.com' => 'shutterstock',
    ];

    /** The test services' ids: one never set in the environment, one always set there. */
    public const TEST_PASTE = 'e2e-paste';

    public const TEST_ENV = 'e2e-env';

    /**
     * @param  array<string, Service>  $services  By id.
     */
    private function __construct(private readonly array $services) {}

    public static function all(): self
    {
        $list = [
            new Service('anthropic', 'Anthropic', Service::WRITING, 'https://platform.claude.com/settings/keys', [new Field(Field::KEY, 'ANTHROPIC_API_KEY')]),
            new Service('openai', 'OpenAI', Service::WRITING, 'https://platform.openai.com/api-keys', [new Field(Field::KEY, 'OPENAI_API_KEY')], makesImages: true),
            new Service('gemini', 'Gemini', Service::WRITING, 'https://aistudio.google.com/apikey', [new Field(Field::KEY, 'GEMINI_API_KEY')], makesImages: true),
            new Service('openrouter', 'OpenRouter', Service::WRITING, 'https://openrouter.ai/settings/keys', [new Field(Field::KEY, 'OPENROUTER_API_KEY')], oauth: Service::OAUTH_KEY, makesImages: true),
            new Service('unsplash', 'Unsplash', Service::IMAGES, 'https://unsplash.com/oauth/applications', [new Field(Field::KEY, 'UNSPLASH_ACCESS_KEY')]),
            new Service('pexels', 'Pexels', Service::IMAGES, 'https://www.pexels.com/api/', [new Field(Field::KEY, 'PEXELS_API_KEY')]),
            new Service('pixabay', 'Pixabay', Service::IMAGES, 'https://pixabay.com/api/docs/', [new Field(Field::KEY, 'PIXABAY_API_KEY')]),
            new Service('openverse', 'Openverse', Service::IMAGES, null, [], steps: 0),
            new Service('shutterstock', 'Shutterstock', Service::STOCK, 'https://www.shutterstock.com/account/developers/apps', [new Field(Field::KEY, 'SHUTTERSTOCK_API_KEY'), new Field(Field::SECRET, 'SHUTTERSTOCK_API_SECRET')], oauth: Service::OAUTH_ACCOUNT),
        ];

        return new self(self::byId($list));
    }

    /**
     * With two services for the end-to-end tests: "Test service", never set
     * in the environment, and "Test service (.env)", set there with a dummy
     * value (GHOSTWRITER_E2E_ENV_KEY). Addons add them only while a fake
     * scenario is playing on a local site, so nobody else sees them.
     */
    public function withTestServices(): self
    {
        return new self($this->services + self::byId([
            new Service(self::TEST_PASTE, 'Test service', Service::TEST, 'https://example.com/keys', [new Field(Field::KEY, 'GHOSTWRITER_E2E_PASTE_KEY')], steps: 1),
            new Service(self::TEST_ENV, 'Test service (.env)', Service::TEST, 'https://example.com/keys', [new Field(Field::KEY, 'GHOSTWRITER_E2E_ENV_KEY')], steps: 1),
        ]));
    }

    public function get(string $id): ?Service
    {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * @return array<string, Service>
     */
    public function list(): array
    {
        return $this->services;
    }

    /**
     * By group, in the page's order; a group with nothing in it is left out.
     *
     * @return array<string, array<int, Service>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach (Service::GROUPS as $group) {
            $in = array_values(array_filter($this->services, fn (Service $service) => $service->group === $group));

            if ($in !== []) {
                $groups[$group] = $in;
            }
        }

        return $groups;
    }

    /**
     * The service and field a Credentials handle names: `pexels` is
     * Pexels' key, `shutterstock_secret` Shutterstock's secret.
     *
     * @return array{0: Service, 1: Field}|null
     */
    public function forHandle(string $handle): ?array
    {
        foreach ($this->services as $service) {
            foreach ($service->fields as $field) {
                if ($field->handle($service->id) === $handle) {
                    return [$service, $field];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, Service>  $list
     * @return array<string, Service>
     */
    private static function byId(array $list): array
    {
        $byId = [];

        foreach ($list as $service) {
            $byId[$service->id] = $service;
        }

        return $byId;
    }
}
