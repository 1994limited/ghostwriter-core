<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use InvalidArgumentException;

/**
 * An image sent to a model, or made by one: its bytes and its type.
 */
final class Image
{
    public function __construct(
        public readonly string $data,
        public readonly string $mime,
    ) {}

    /**
     * @throws InvalidArgumentException when the bytes are not an image.
     */
    public static function fromString(string $data): self
    {
        $size = $data === '' ? false : @getimagesizefromstring($data);

        if ($size === false) {
            throw new InvalidArgumentException('That file is not an image.');
        }

        return new self($data, (string) $size['mime']);
    }

    /**
     * @throws InvalidArgumentException when the file is missing or not an image.
     */
    public static function fromPath(string $path): self
    {
        return self::fromString((string) @file_get_contents($path));
    }

    public function base64(): string
    {
        return base64_encode($this->data);
    }

    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    /** Size in bytes, for request size limits. */
    public function bytes(): int
    {
        return strlen($this->data);
    }
}
