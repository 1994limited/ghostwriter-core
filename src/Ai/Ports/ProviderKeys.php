<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

use SensitiveParameter;

/**
 * Where a site keeps the API keys it got by connecting a provider account
 * ("Connect with OpenRouter"), by provider. Per site (per tenant in
 * Filament), not per CMS user.
 *
 * The addon implements it and **encrypts the keys at rest** (Laravel's
 * Crypt, Craft's security service); it never logs them or shows them back.
 * Keys from the environment are not kept here: they are read through
 * Credentials every time, and always win (Credentials\ConnectedCredentials).
 */
interface ProviderKeys
{
    /** The kept key, or null when none is. */
    public function get(string $provider): ?string;

    public function put(string $provider, #[SensitiveParameter] string $key): void;

    public function forget(string $provider): void;
}
