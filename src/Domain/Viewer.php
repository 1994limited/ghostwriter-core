<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * The person asking: their user ID as the CMS stores it, whether they may
 * manage Ghostwriter (its settings permission: Statamic's "edit
 * ghostwriter settings", Craft's admin or manage permission, Filament's
 * manage ability), and whether the CMS treats them as able to see
 * everything (a Statamic super user).
 */
final class Viewer
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly bool $manager = false,
        public readonly bool $admin = false,
    ) {}

    public static function nobody(): self
    {
        return new self(null);
    }

    public function isSomeone(): bool
    {
        return $this->id !== null && $this->id !== '';
    }

    /**
     * Whether a stored user reference is this person. IDs are compared as
     * text, since one CMS keeps them as strings and another as integers.
     */
    public function is(int|string|null $userId): bool
    {
        return $this->isSomeone() && $userId !== null && $userId !== '' && (string) $userId === (string) $this->id;
    }
}
