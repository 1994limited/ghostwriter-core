<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

/**
 * Who did something to a stock image: their user ID as the CMS stores it,
 * and their name as it was then, so the ledger still reads after the user
 * is renamed or deleted.
 */
final class Person
{
    public function __construct(
        public readonly int|string|null $id,
        public readonly ?string $name = null,
    ) {}

    /**
     * @return array{id: int|string|null, name: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;

        return new self(is_int($id) || is_string($id) ? $id : null, is_string($name) ? $name : null);
    }
}
