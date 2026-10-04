<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * Where an addon keeps its render profiles (Statamic a JSON file, Craft
 * its state store), one per group, site and blueprint or entry type.
 */
interface RenderProfiles
{
    /** The stored profile, or null before any preview of that group. */
    public function get(string $key): ?RenderProfile;

    public function put(RenderProfile $profile): void;

    /**
     * Every stored profile, for the developer note.
     *
     * @return list<RenderProfile>
     */
    public function all(): array;
}
