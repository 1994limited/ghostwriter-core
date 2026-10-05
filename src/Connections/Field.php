<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * One thing a service needs pasted: its key, a secret beside it, or an
 * access token, and the environment variable that sets it instead.
 *
 * Through Ai\Ports\Credentials a field is read by its handle: the service's
 * id for the key (`pexels`), the id and the field's name otherwise
 * (`shutterstock_secret`), as the addons always have.
 */
final class Field
{
    public const KEY = 'key';

    public const SECRET = 'secret';

    public const TOKEN = 'token';

    public function __construct(
        public readonly string $name,
        public readonly string $env,
        public readonly bool $optional = false,
    ) {}

    /** The name Credentials::key() knows it by: `pexels`, `shutterstock_secret`. */
    public function handle(string $service): string
    {
        return $this->name === self::KEY ? $service : $service.'_'.$this->name;
    }
}
