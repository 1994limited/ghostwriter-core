<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;

/**
 * Render profiles kept in memory, for tests.
 */
final class InMemoryRenderProfiles implements RenderProfiles
{
    /** @var array<string, array<string, mixed>> */
    private array $profiles = [];

    public function get(string $key): ?RenderProfile
    {
        return isset($this->profiles[$key]) ? RenderProfile::fromArray($this->profiles[$key]) : null;
    }

    public function put(RenderProfile $profile): void
    {
        $this->profiles[$profile->key] = $profile->toArray();
    }

    public function all(): array
    {
        return array_values(array_map(fn (array $profile) => RenderProfile::fromArray($profile), $this->profiles));
    }
}
