<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use DateTimeImmutable;

/**
 * Who a paid library is connected as, and what the account can buy: for
 * "Check connection" in the settings and the cost line at confirm ("Uses
 * 1 of your 742 remaining downloads (Premium Access, resets 1 Nov)").
 * Each product: its ID and type, a name, what is left (`remaining`, in
 * downloads or credits; null when the library doesn't say), when that
 * resets, and when the product's term ends.
 */
final class Account
{
    /**
     * @param  array<int, array{id: string, type: ?string, name: string, remaining: ?Cost, resetsAt: ?DateTimeImmutable, termEndsAt: ?DateTimeImmutable}>  $products
     */
    public function __construct(
        public readonly string $library,
        public readonly ?string $name = null,
        public readonly array $products = [],
    ) {}

    /**
     * @return array{id: string, type: ?string, name: string, remaining: ?Cost, resetsAt: ?DateTimeImmutable, termEndsAt: ?DateTimeImmutable}|null
     */
    public function product(string $id): ?array
    {
        foreach ($this->products as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }
}
