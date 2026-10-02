<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use DateTimeInterface;

/**
 * What the addon knows about the record a piece is for, so core can say
 * where the piece has got to and whether it is finished (E6): whether there
 * is one, whether it is saved (not just an unpublished draft, in Craft),
 * whether it is live, and when it was last saved.
 */
final class Record
{
    public function __construct(
        public readonly bool $exists = false,
        public readonly bool $saved = false,
        public readonly bool $published = false,
        public readonly ?DateTimeInterface $savedAt = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function saved(bool $published = false, ?DateTimeInterface $savedAt = null): self
    {
        return new self(true, true, $published, $savedAt);
    }

    /**
     * A record that exists but isn't saved as content yet (a Craft
     * unpublished draft).
     */
    public static function unsaved(): self
    {
        return new self(true, false);
    }
}
