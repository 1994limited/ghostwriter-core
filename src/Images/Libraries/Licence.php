<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use DateTimeImmutable;

/**
 * A licence bought: from which library, for which photo, the provider's
 * order or licence ID, the option it was bought under, what it cost (as
 * charged where the provider says, else as quoted: `estimated`), when and
 * by whom, the credit line and restrictions that come with it, and for
 * the seat and storage terms the product it came from and when that
 * product's term ends.
 *
 * `key` is the idempotency key the purchase was made with (the ledger
 * record's ID), when the provider echoes a client reference back. `raw`
 * is the provider's answer, for the audit trail, with any address that
 * could carry a token taken out (trim()).
 */
final class Licence
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $library,
        public readonly string $photoId,
        public readonly string $orderId,
        public readonly DateTimeImmutable $licensedAt,
        public readonly string $licensedBy = '',
        public readonly ?string $option = null,
        public readonly ?Cost $cost = null,
        public readonly bool $estimated = false,
        public readonly ?string $creditLine = null,
        public readonly string $licenceType = Offer::ROYALTY_FREE,
        public readonly ?string $restrictions = null,
        public readonly ?string $productType = null,
        public readonly ?DateTimeImmutable $termEndsAt = null,
        public readonly ?DateTimeImmutable $downloadUrlExpiresAt = null,
        public readonly ?string $terms = null,
        public readonly ?string $key = null,
        public readonly array $raw = [],
    ) {}

    /**
     * The provider's answer without anything that looks like an address
     * with a query string or a token: download links are signed.
     *
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    public static function trim(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $out[(string) $key] = self::trim($value);
            } elseif (is_string($value) && (preg_match('#^[a-z][a-z0-9+.-]*://[^\s]*\?#i', $value) || preg_match('/token|signature|secret|password/i', (string) $key))) {
                continue;
            } else {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'library' => $this->library,
            'photo_id' => $this->photoId,
            'order_id' => $this->orderId,
            'licensed_at' => $this->licensedAt->format(DATE_ATOM),
            'licensed_by' => $this->licensedBy,
            'option' => $this->option,
            'cost' => $this->cost?->toArray(),
            'estimated' => $this->estimated,
            'credit_line' => $this->creditLine,
            'licence_type' => $this->licenceType,
            'restrictions' => $this->restrictions,
            'product_type' => $this->productType,
            'term_ends_at' => $this->termEndsAt?->format(DATE_ATOM),
            'download_url_expires_at' => $this->downloadUrlExpiresAt?->format(DATE_ATOM),
            'terms' => $this->terms,
            'key' => $this->key,
            'raw' => self::trim($this->raw),
        ];
    }

    /**
     * Back from toArray(). Null when it doesn't hold a licence.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $string = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '' ? (string) $data[$key] : null;
        $licensedAt = Quote::date($data['licensed_at'] ?? null);

        if ($string('library') === null || $string('photo_id') === null || $string('order_id') === null || $licensedAt === null) {
            return null;
        }

        $type = $string('licence_type');
        $raw = is_array($data['raw'] ?? null) ? $data['raw'] : [];

        return new self(
            (string) $string('library'),
            (string) $string('photo_id'),
            (string) $string('order_id'),
            $licensedAt,
            $string('licensed_by') ?? '',
            $string('option'),
            is_array($data['cost'] ?? null) ? Cost::fromArray($data['cost']) : null,
            (bool) ($data['estimated'] ?? false),
            $string('credit_line'),
            $type !== null && in_array($type, Offer::LICENCE_TYPES, true) ? $type : Offer::ROYALTY_FREE,
            $string('restrictions'),
            $string('product_type'),
            Quote::date($data['term_ends_at'] ?? null),
            Quote::date($data['download_url_expires_at'] ?? null),
            $string('terms'),
            $string('key'),
            self::trim($raw),
        );
    }
}
