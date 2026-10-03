<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

/**
 * One extra the writer prepared: a kind ("stats") and its items. Its id is
 * "x<n>", and its items' ids start with it.
 */
final class Extra
{
    /**
     * @param  list<ExtraItem>  $items
     */
    public function __construct(
        public readonly string $id,
        public readonly ExtraKind $kind,
        public readonly array $items,
    ) {}

    /**
     * @return array{id: string, kind: string, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind->value, 'items' => array_map(fn (ExtraItem $item) => $item->toArray(), $this->items)];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $kind = ExtraKind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '');

        if ($kind === null) {
            return null;
        }

        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            $kind,
            array_values(array_map(fn (array $item) => ExtraItem::fromArray($item), array_filter(is_array($array['items'] ?? null) ? $array['items'] : [], 'is_array'))),
        );
    }
}
