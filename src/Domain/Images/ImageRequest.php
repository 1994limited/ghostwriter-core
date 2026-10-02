<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Images;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Work the image button has asked for: a search (`find`) or a picture
 * being made (`make`) for one field on one form, for the person who asked.
 *
 *     working ──▶ done      (results, or the picture, to choose from)
 *        │
 *        └─────▶ failed    (also when its job stopped without saying so)
 *
 * A request is its owner's alone, runs in the background, and is cleared a
 * day later with any picture it made that wasn't kept (KEEP_FOR).
 *
 * Kept as Statamic's `images/<id>.json` files, Craft's `image:<id>` state
 * and Filament's `image:<id>` state rows. Statamic calls the finished
 * state `done`, Craft and Filament `ready`; the stored word is kept.
 * Everything else a request holds (the field it is for, the results, the
 * direction, the picture's MIME type) is in `details`, in the addon's keys.
 */
final class ImageRequest
{
    use RoundTrips;

    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const FIND = 'find';

    public const MAKE = 'make';

    /** Seconds a request is kept. */
    public const KEEP_FOR = 86400;

    /** When the request was last saved, as the store knows it (not part of the record). */
    public DateTimeInterface|string|int|null $changedAt = null;

    /**
     * @param  string  $status  WORKING, DONE or FAILED.
     * @param  int|string|null  $owner  The user who asked.
     * @param  string|int|null  $createdAt  As the format keeps it: ISO 8601 (Statamic) or a Unix timestamp.
     * @param  array<int, string>  $terms  The searches run.
     * @param  array<int, array<string, mixed>>  $options  The photographs found.
     * @param  string|null  $file  Where a made picture waits (a path, or a store's reference).
     * @param  array<string, mixed>  $details  The rest, in the addon's keys.
     */
    public function __construct(
        public readonly Format $format,
        public readonly string $id,
        public readonly string $mode,
        public string $status = self::WORKING,
        public ?string $error = null,
        public int|string|null $owner = null,
        public string|int|null $createdAt = null,
        public array $terms = [],
        public array $options = [],
        public ?string $file = null,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function start(Format $format, string $mode, int|string|null $owner, array $details = [], ?DateTimeInterface $now = null): self
    {
        $now ??= new DateTimeImmutable;

        return new self(
            $format,
            $format->newId(),
            $mode,
            owner: $format === Format::Statamic ? (string) $owner : (is_numeric($owner) ? (int) $owner : $owner),
            createdAt: $format === Format::Statamic ? $now->format(DATE_ATOM) : $now->getTimestamp(),
            details: $details,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Format $format): self
    {
        $ownerKey = self::ownerKey($format);
        $createdKey = $format === Format::Craft ? 'createdAt' : 'created_at';
        $status = is_string($data['status'] ?? null) ? $data['status'] : self::WORKING;
        $owner = $data[$ownerKey] ?? null;
        $created = $data[$createdKey] ?? null;
        $known = ['id', 'mode', 'status', 'error', $ownerKey, $createdKey, 'terms', 'options', 'file'];

        $request = new self(
            $format,
            is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
            is_scalar($data['mode'] ?? null) ? (string) $data['mode'] : self::FIND,
            $status === 'ready' ? self::DONE : $status,
            is_scalar($data['error'] ?? null) ? (string) $data['error'] : null,
            is_int($owner) || is_string($owner) ? $owner : null,
            is_int($created) || is_string($created) ? $created : null,
            array_values(array_filter((array) ($data['terms'] ?? []), 'is_string')),
            array_values(array_filter((array) ($data['options'] ?? []), 'is_array')),
            is_string($data['file'] ?? null) ? $data['file'] : null,
            array_diff_key($data, array_flip($known)),
        );

        return $request->remember($data, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    public function isWorking(): bool
    {
        return $this->status === self::WORKING;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    /**
     * The search or picture is ready.
     *
     * @param  array<string, mixed>  $details  Results and anything else, in the addon's keys.
     */
    public function succeed(array $details = [], ?string $file = null): void
    {
        $this->status = self::DONE;
        $this->error = null;
        $this->file = $file ?? $this->file;

        if (array_key_exists('terms', $details)) {
            $this->terms = array_values(array_filter((array) $details['terms'], 'is_string'));
            unset($details['terms']);
        }

        if (array_key_exists('options', $details)) {
            $this->options = array_values(array_filter((array) $details['options'], 'is_array'));
            unset($details['options']);
        }

        $this->details = $details + $this->details;
    }

    public function fail(string $error): void
    {
        $this->status = self::FAILED;
        $this->error = $error;
    }

    /**
     * Whether it belongs to the person (Q: requests are never shared).
     */
    public function isOwnedBy(Viewer $viewer): bool
    {
        return $viewer->is($this->owner);
    }

    /**
     * Still working long after any job could be running (CRA-2), timed
     * from its last save where the store knows it, or from when it was made.
     */
    public function isStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        $since = Format::parse($this->changedAt ?? $this->createdAt);

        return $this->isWorking() && $since !== null && $since->getTimestamp() < ($now ?? new DateTimeImmutable)->getTimestamp() - $options->staleAfter();
    }

    public function recoverIfStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        if (! $this->isStale($options, $now)) {
            return false;
        }

        $this->fail(DomainOptions::STOPPED);

        return true;
    }

    /**
     * Older than KEEP_FOR: to be cleared, with its files.
     */
    public function isExpired(?DateTimeInterface $now = null): bool
    {
        $since = Format::parse($this->changedAt ?? $this->createdAt);

        return $since !== null && $since->getTimestamp() < ($now ?? new DateTimeImmutable)->getTimestamp() - self::KEEP_FOR;
    }

    /**
     * The stored word for the state: Statamic's `done` is Craft's and
     * Filament's `ready`.
     */
    public function storedStatus(Format $format): string
    {
        return $this->status === self::DONE && $format !== Format::Statamic ? 'ready' : $this->status;
    }

    public static function ownerKey(Format $format): string
    {
        return match ($format) {
            Format::Statamic => 'user',
            Format::Craft => 'userId',
            Format::Filament => 'user_id',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->storedStatus($format),
            'error' => $this->error,
            self::ownerKey($format) => $this->owner,
            ($format === Format::Craft ? 'createdAt' : 'created_at') => $this->createdAt,
            'terms' => $this->terms,
            'options' => $this->options,
            'file' => $this->file,
        ] + $this->details;
    }
}
