<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;

/**
 * LibraryTokens in memory, for tests.
 */
final class InMemoryLibraryTokens implements LibraryTokens
{
    /** @var array<string, TokenSet> */
    public array $tokens = [];

    public function get(string $library): ?TokenSet
    {
        return $this->tokens[$library] ?? null;
    }

    public function put(string $library, TokenSet $tokens): void
    {
        $this->tokens[$library] = $tokens;
    }

    public function forget(string $library): void
    {
        unset($this->tokens[$library]);
    }
}
