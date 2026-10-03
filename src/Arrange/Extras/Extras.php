<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The extras a session has (Session::$extras), in the order the writer gave
 * them. Read from the writer's reply with ExtrasReader; edited and deleted
 * by the editor in the Text tab.
 *
 * @implements IteratorAggregate<int, Extra>
 */
final class Extras implements Countable, IteratorAggregate
{
    /**
     * @param  list<Extra>  $extras
     */
    public function __construct(private readonly array $extras = []) {}

    /**
     * @return list<Extra>
     */
    public function all(): array
    {
        return $this->extras;
    }

    /**
     * Every item, by id.
     *
     * @return array<string, ExtraItem>
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->extras as $extra) {
            foreach ($extra->items as $item) {
                $items[$item->id] = $item;
            }
        }

        return $items;
    }

    /** An item by its id ("x2.1"), or by a part's ref ("x2.1.question"). */
    public function item(string $ref): ?ExtraItem
    {
        return $this->items()[self::itemId($ref)] ?? null;
    }

    /** The extra an item belongs to. */
    public function extraOf(string $ref): ?Extra
    {
        $id = self::itemId($ref);

        foreach ($this->extras as $extra) {
            foreach ($extra->items as $item) {
                if ($item->id === $id) {
                    return $extra;
                }
            }
        }

        return null;
    }

    /** These extras without one item; an extra left with no items goes too. */
    public function without(string $itemId): self
    {
        $extras = [];

        foreach ($this->extras as $extra) {
            $items = array_values(array_filter($extra->items, fn (ExtraItem $item) => $item->id !== $itemId));

            if ($items !== []) {
                $extras[] = new Extra($extra->id, $extra->kind, $items);
            }
        }

        return new self($extras);
    }

    /**
     * These extras with one item changed by the editor: their words become
     * its source (decision 4), and any `[[ask: …]]` left in them is still
     * asked.
     *
     * @param  array<string, string>|null  $parts
     */
    public function edit(string $itemId, string $text, ?array $parts = null): self
    {
        return new self(array_map(fn (Extra $extra) => new Extra($extra->id, $extra->kind, array_map(
            fn (ExtraItem $item) => $item->id === $itemId ? $item->editedTo($text, $parts) : $item,
            $extra->items,
        )), $this->extras));
    }

    public function count(): int
    {
        return count($this->extras);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->extras);
    }

    /**
     * What Session::$extras stores.
     *
     * @return list<array{id: string, kind: string, items: list<array<string, mixed>>}>
     */
    public function toArray(): array
    {
        return array_map(fn (Extra $extra) => $extra->toArray(), $this->extras);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $extras = [];

        foreach ($array as $extra) {
            if (is_array($extra) && ($read = Extra::fromArray($extra)) !== null) {
                $extras[] = $read;
            }
        }

        return new self($extras);
    }

    /** "x2.1" from "x2.1.question". */
    public static function itemId(string $ref): string
    {
        return preg_match('/^(x\d+\.\d+)/', $ref, $m) === 1 ? $m[1] : $ref;
    }

    /** "question" from "x2.1.question"; null for the item itself. */
    public static function partOf(string $ref): ?string
    {
        return preg_match('/^x\d+\.\d+\.([a-z_]+)$/', $ref, $m) === 1 ? $m[1] : null;
    }
}
