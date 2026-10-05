<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;

/**
 * Images\Libraries\Ports\LibraryTokens over the Connections store: a paid
 * library's connected-account tokens, kept as `tokens:<library>` beside
 * its key, encrypted the same way.
 */
final class StoredLibraryTokens implements LibraryTokens
{
    public function __construct(private readonly CredentialStore $store) {}

    public function get(string $library): ?TokenSet
    {
        $data = $this->store->get(self::name($library));

        return is_array($data) ? TokenSet::fromArray($data) : null;
    }

    public function put(string $library, TokenSet $tokens): void
    {
        $this->store->put(self::name($library), $tokens->toArray());
    }

    public function forget(string $library): void
    {
        $this->store->forget(self::name($library));
    }

    public static function name(string $library): string
    {
        return 'tokens:'.$library;
    }
}
