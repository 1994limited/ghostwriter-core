<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Images;

/**
 * A picture kept beside an image request until it is used or cleared: the
 * one made (`made`), or one uploaded to build it around (`source`).
 */
final class StoredFile
{
    public const MADE = 'made';

    public const SOURCE = 'source';

    public function __construct(
        public readonly string $content,
        public readonly string $mime,
        public readonly string $extension,
    ) {}
}
