<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;

/**
 * A paid library's comp: the watermarked preview of a photo not yet
 * licensed, for the control panel only. Its terms allow "test or sample"
 * use for a while (`keepUntil`), and never at a public address, so it is
 * never saved as a CMS asset.
 *
 * Either the bytes (`file`), kept in Ghostwriter's private storage until
 * `keepUntil`, or, where the library's terms allow no storage at all
 * (Capabilities::STORAGE_NONE), only the provider's own preview address
 * (`url`), shown to signed-in editors and never fetched by Ghostwriter.
 */
final class Preview
{
    public function __construct(
        public readonly ?PhotoFile $file,
        public readonly ?string $url,
        public readonly bool $watermarked,
        public readonly DateTimeImmutable $fetchedAt,
        public readonly DateTimeImmutable $keepUntil,
    ) {
        if (($file === null) === ($url === null)) {
            throw new InvalidArgumentException('A preview is either its bytes or the provider\'s address, not both or neither.');
        }

        if ($keepUntil < $fetchedAt) {
            throw new InvalidArgumentException('A preview can\'t expire before it was fetched.');
        }
    }

    /**
     * Bytes kept privately for $keepDays (the library's previewKeepDays).
     */
    public static function stored(PhotoFile $file, int $keepDays, bool $watermarked = true, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable;

        return new self($file, null, $watermarked, $now, $now->modify('+'.max(0, $keepDays).' days'));
    }

    /**
     * The provider's own preview address, for a library whose terms allow
     * nothing to be stored.
     */
    public static function linked(string $url, int $keepDays, bool $watermarked = true, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable;

        return new self(null, $url, $watermarked, $now, $now->modify('+'.max(0, $keepDays).' days'));
    }

    public function isStored(): bool
    {
        return $this->file !== null;
    }

    /** Past the provider's comp period: the bytes must go. */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return ($now ?? new DateTimeImmutable) >= $this->keepUntil;
    }
}
