<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One licence option a library offers this account for one photo, priced
 * as well as the library can before buying: Getty's product and type,
 * iStock's credits for a licence type, a size, standard or extended. The
 * person confirms one; licensing uses it, and the library refuses it
 * (QuoteChanged) if the price or option has changed since.
 */
final class Quote
{
    public function __construct(
        public readonly string $photoId,
        public readonly string $option,
        public readonly string $licenceName,
        public readonly ?Cost $cost = null,
        public readonly ?string $productType = null,
        public readonly ?string $size = null,
        public readonly bool $extended = false,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?string $terms = null,
    ) {}

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        return $this->expiresAt !== null && ($now ?? new DateTimeImmutable) >= $this->expiresAt;
    }

    /** The cost in words for the confirm step: "1 download", or that it isn't known before licensing. */
    public function costLabel(): string
    {
        return $this->cost?->label() ?? 'not known before licensing';
    }

    /**
     * @return array{photo_id: string, option: string, licence_name: string, cost: ?array<string, mixed>, product_type: ?string, size: ?string, extended: bool, expires_at: ?string, terms: ?string}
     */
    public function toArray(): array
    {
        return [
            'photo_id' => $this->photoId,
            'option' => $this->option,
            'licence_name' => $this->licenceName,
            'cost' => $this->cost?->toArray(),
            'product_type' => $this->productType,
            'size' => $this->size,
            'extended' => $this->extended,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'terms' => $this->terms,
        ];
    }

    /**
     * Back from toArray(). Null when it doesn't hold a quote.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? (string) $data[$key] : null;

        if ($string('photo_id') === null || $string('option') === null) {
            return null;
        }

        return new self(
            (string) $string('photo_id'),
            (string) $string('option'),
            $string('licence_name') ?? '',
            is_array($data['cost'] ?? null) ? Cost::fromArray($data['cost']) : null,
            $string('product_type'),
            $string('size'),
            (bool) ($data['extended'] ?? false),
            self::date($data['expires_at'] ?? null),
            $string('terms'),
        );
    }

    /** @internal */
    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
