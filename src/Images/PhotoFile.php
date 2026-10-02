<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

/**
 * A downloaded photograph: its bytes, checked to be a JPEG, PNG or WebP
 * image no bigger than StockSearch::MAX_BYTES, and the photo it is, looked
 * up again from the library (credit, licence, title and description).
 */
final class PhotoFile
{
    public function __construct(
        public readonly string $content,
        public readonly string $mime,
        public readonly string $extension,
        public readonly Photo $photo,
    ) {}

    public function bytes(): int
    {
        return strlen($this->content);
    }
}
