<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * How an addon stores Ghostwriter's records today, so the domain types can
 * read and write them without a data migration:
 *
 * - **Statamic:** JSON and YAML files, keys in snake_case, dates as ISO 8601,
 *   IDs as upper-case ULIDs.
 * - **Craft:** rows in its own tables, the record as decoded JSON, keys in
 *   snake_case for sessions and camelCase elsewhere, user and element IDs as
 *   integers, IDs as 26 hex digits.
 * - **Filament:** Eloquent rows exactly as stored (`getAttributes()`): JSON
 *   columns as JSON strings, dates as `Y-m-d H:i:s`, lower-case ULIDs.
 *
 * Each value object's `fromArray($data, $format)` reads the shape, and
 * `toArray($format)` gives it back, key for key, for anything not changed.
 */
enum Format: string
{
    case Statamic = 'statamic';
    case Craft = 'craft';
    case Filament = 'filament';

    /**
     * A moment as this format writes it into a record: ISO 8601 with the
     * offset for Statamic and Craft, a database timestamp for Filament.
     */
    public function stamp(DateTimeInterface $at): string
    {
        return $this === self::Filament
            ? DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : $at->format(DATE_ATOM);
    }

    /**
     * A stored moment read back: ISO 8601, a database timestamp (taken as
     * UTC, Laravel's default) or a Unix timestamp. Null when there is none
     * or it can't be read.
     */
    public static function parse(DateTimeInterface|int|string|null $at): ?DateTimeImmutable
    {
        if ($at instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($at);
        }

        if ($at === null || $at === '') {
            return null;
        }

        if (is_int($at) || ctype_digit($at)) {
            return (new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('UTC'));
        }

        try {
            return new DateTimeImmutable($at, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * A new ID for a session, an image request or (Statamic, Craft) an idea.
     */
    public function newId(): string
    {
        return match ($this) {
            self::Statamic => Ulid::generate(),
            self::Filament => strtolower(Ulid::generate()),
            self::Craft => bin2hex(random_bytes(13)),
        };
    }

    /**
     * Whether a string could be one of this format's session IDs, so a
     * store need not look up anything else.
     */
    public function isSessionId(string $id): bool
    {
        return match ($this) {
            self::Statamic => (bool) preg_match('/^[0-9A-Za-z]{26}$/', $id),
            self::Filament => (bool) preg_match('/^[0-9a-z]{26}$/', $id),
            self::Craft => (bool) preg_match('/^[0-9a-f]{26}$/', $id),
        };
    }

    /**
     * The JSON a column is written with: Laravel's own encoding for
     * Filament's casts (slashes and non-ASCII escaped); the others hold
     * decoded arrays.
     */
    public function json(mixed $value): string
    {
        return (string) json_encode($value);
    }

    /**
     * What the group a kind of content belongs to is called in this
     * format's records: a collection, a section or a resource.
     */
    public function groupKey(): string
    {
        return match ($this) {
            self::Statamic => 'collection',
            self::Craft => 'section',
            self::Filament => 'resource',
        };
    }
}
