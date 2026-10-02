<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Planning;

use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;

/**
 * One idea on the content plan: something the site doesn't have yet,
 * suggested by Ghostwriter or added by hand.
 *
 *     open ──start──▶ drafted ──(its piece deleted)──▶ open
 *       │                                               ▲
 *       └──dismiss──▶ dismissed ──put back (E8)─────────┘
 *
 * Kept as Statamic's `ideas.yaml` list items, Craft's `idea` documents and
 * Filament's `ghostwriter_ideas` rows. The rules are on Plan.
 */
final class Idea
{
    use RoundTrips;

    public const OPEN = 'open';

    /** A piece has been started from it. */
    public const DRAFTED = 'drafted';

    /** Kept, so it isn't suggested again. */
    public const DISMISSED = 'dismissed';

    public const SUGGESTED = 'suggested';

    public const ADDED = 'added';

    /**
     * @param  int|string|null  $id  Null until a store saves it.
     * @param  string  $group  The collection, section or resource it is for.
     * @param  string|null  $kind  The kind of content, or null for "Something new".
     * @param  int|string|null  $session  The piece started from it, as the store refers to it.
     */
    public function __construct(
        public readonly Format $format,
        public int|string|null $id,
        public string $title,
        public string $group,
        public ?string $kind = null,
        public string $why = '',
        public string $notes = '',
        public string $status = self::OPEN,
        public string $source = self::ADDED,
        public int|string|null $session = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * A new idea, not yet saved: added by hand, or kept from a suggestion.
     *
     * @param  array<string, mixed>  $idea  `title`, the group under any of the addons' keys, `type` or `kind`, `why`, `notes`.
     */
    public static function make(Format $format, array $idea, string $source = self::ADDED, ?DateTimeInterface $now = null): self
    {
        $now ??= new DateTimeImmutable;
        $group = '';

        foreach ([$format->groupKey(), 'collection', 'section', 'resource', 'group'] as $key) {
            if (is_scalar($idea[$key] ?? null) && (string) $idea[$key] !== '') {
                $group = (string) $idea[$key];

                break;
            }
        }

        $kind = $idea['type'] ?? $idea['kind'] ?? null;

        return new self(
            $format,
            $format === Format::Filament ? null : ($format === Format::Craft ? bin2hex(random_bytes(8)) : $format->newId()),
            trim(self::text($idea['title'] ?? '')),
            $group,
            is_scalar($kind) && (string) $kind !== '' ? (string) $kind : null,
            trim(self::text($idea['why'] ?? '')),
            trim(self::text($idea['notes'] ?? '')),
            self::OPEN,
            $source,
            null,
            $format === Format::Filament ? $format->stamp($now) : $now->format('Y-m-d'),
            $format === Format::Filament ? $format->stamp($now) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Format $format): self
    {
        $kind = $format === Format::Filament ? ($data['kind'] ?? null) : ($data['type'] ?? null);
        $session = $format === Format::Filament ? ($data['session_id'] ?? null) : ($data['session'] ?? null);
        $created = $format === Format::Craft ? ($data['createdAt'] ?? null) : ($data['created_at'] ?? null);

        $idea = new self(
            $format,
            is_int($data['id'] ?? null) || (is_string($data['id'] ?? null) && $data['id'] !== '') ? $data['id'] : null,
            self::text($data['title'] ?? ''),
            self::text($data[$format->groupKey()] ?? ''),
            is_scalar($kind) && (string) $kind !== '' ? (string) $kind : null,
            self::text($data['why'] ?? ''),
            self::text($data['notes'] ?? ''),
            self::text($data['status'] ?? self::OPEN) ?: self::OPEN,
            self::text($data['source'] ?? self::ADDED) ?: self::ADDED,
            is_int($session) || (is_string($session) && $session !== '') ? $session : null,
            is_scalar($created) ? (string) $created : null,
            is_scalar($data['updated_at'] ?? null) ? (string) $data['updated_at'] : null,
        );

        return $idea->remember($data, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isDrafted(): bool
    {
        return $this->status === self::DRAFTED;
    }

    public function isDismissed(): bool
    {
        return $this->status === self::DISMISSED;
    }

    /**
     * The title as compared for duplicates: trimmed, any case.
     */
    public function key(): string
    {
        return self::titleKey($this->title);
    }

    public static function titleKey(string $title): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        return match ($format) {
            Format::Statamic => [
                'id' => $this->id === null ? null : (string) $this->id,
                'title' => $this->title,
                'collection' => $this->group,
                'type' => $this->kind,
                'why' => $this->why,
                'notes' => $this->notes,
                'status' => $this->status,
                'source' => $this->source,
                'session' => $this->session === null ? null : (string) $this->session,
                'created_at' => $this->createdAt,
            ],
            Format::Craft => [
                'id' => $this->id === null ? null : (string) $this->id,
                'title' => $this->title,
                'section' => $this->group,
                'type' => $this->kind,
                'why' => $this->why,
                'notes' => $this->notes,
                'status' => $this->status,
                'source' => $this->source,
                'session' => $this->session === null ? null : (string) $this->session,
                'createdAt' => $this->createdAt,
            ],
            Format::Filament => array_filter(['id' => $this->id], fn ($value) => $value !== null) + [
                'resource' => $this->group,
                'kind' => $this->kind,
                'title' => $this->title,
                'why' => $this->why === '' ? null : $this->why,
                'notes' => $this->notes === '' ? null : $this->notes,
                'status' => $this->status,
                'source' => $this->source,
                'session_id' => is_numeric($this->session) ? (int) $this->session : $this->session,
                'created_at' => $this->createdAt,
                'updated_at' => $this->updatedAt,
            ],
        };
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
