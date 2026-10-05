<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use RuntimeException;

/**
 * Why a key couldn't be set up or forgotten, as a Strings key and its
 * parameters, so the page can say it in the editor's language. The message
 * is the English, and never holds the key.
 */
final class ConnectionRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(Strings::english()->get($key, $params));
    }

    public function translated(Strings $strings): string
    {
        return $strings->get($this->key, $this->params);
    }
}
