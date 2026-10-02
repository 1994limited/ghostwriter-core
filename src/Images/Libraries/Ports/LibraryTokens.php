<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;

/**
 * Where a site keeps its libraries' tokens: cached client-credentials
 * tokens (Getty 30 minutes, Alamy 24 hours), and the user tokens of
 * libraries that need a connected account. Per site (per tenant in
 * Filament), not per CMS user: licences belong to the company.
 *
 * The addon encrypts them at rest (Laravel's Crypt, Craft's security
 * service). Keys and secrets are not kept here: they are read from the
 * environment through Credentials, every time.
 */
interface LibraryTokens
{
    public function get(string $library): ?TokenSet;

    public function put(string $library, TokenSet $tokens): void;

    public function forget(string $library): void;
}
