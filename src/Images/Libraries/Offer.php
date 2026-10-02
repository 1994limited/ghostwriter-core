<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use InvalidArgumentException;

/**
 * How a photo can be had, as its library says in search results: free, or
 * paid with a price hint for the card ("1 download", "3 credits", or none
 * known: "Paid"), its licence type, and the products the account could
 * license it under, when search says.
 *
 * A Photo from a free library carries no offer (null): free, as photos
 * always were. Photo::offer() fills that in.
 */
final class Offer
{
    public const ROYALTY_FREE = 'royalty_free';

    public const RIGHTS_MANAGED = 'rights_managed';

    public const EDITORIAL = 'editorial';

    public const FREE_LICENCE = 'free_licence';

    public const LICENCE_TYPES = [self::ROYALTY_FREE, self::RIGHTS_MANAGED, self::EDITORIAL, self::FREE_LICENCE];

    /**
     * @param  array<int, string>  $products  Product or plan IDs the account could use.
     */
    public function __construct(
        public readonly bool $free,
        public readonly ?Cost $price = null,
        public readonly string $licenceType = self::FREE_LICENCE,
        public readonly array $products = [],
    ) {
        if (! in_array($licenceType, self::LICENCE_TYPES, true)) {
            throw new InvalidArgumentException("Unknown licence type \"{$licenceType}\".");
        }
    }

    public static function free(): self
    {
        return new self(true);
    }

    /**
     * @param  array<int, string>  $products
     */
    public static function paid(?Cost $price = null, string $licenceType = self::ROYALTY_FREE, array $products = []): self
    {
        return new self(false, $price, $licenceType, array_values($products));
    }

    /** For the card: "Free", the price hint, or "Paid" when nothing is known before licensing. */
    public function label(): string
    {
        return $this->free ? 'Free' : ($this->price?->label() ?? 'Paid');
    }

    /**
     * @return array{free: bool, price: ?array<string, mixed>, licence_type: string, products: array<int, string>, label: string}
     */
    public function toArray(): array
    {
        return [
            'free' => $this->free,
            'price' => $this->price?->toArray(),
            'licence_type' => $this->licenceType,
            'products' => $this->products,
            'label' => $this->label(),
        ];
    }

    /**
     * Back from toArray(). Null when it doesn't hold an offer.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_bool($data['free'] ?? null)) {
            return null;
        }

        $type = is_string($data['licence_type'] ?? null) && in_array($data['licence_type'], self::LICENCE_TYPES, true)
            ? $data['licence_type']
            : ($data['free'] ? self::FREE_LICENCE : self::ROYALTY_FREE);
        $products = is_array($data['products'] ?? null) ? array_values(array_filter($data['products'], 'is_string')) : [];

        return new self(
            $data['free'],
            is_array($data['price'] ?? null) ? Cost::fromArray($data['price']) : null,
            $type,
            $products,
        );
    }
}
