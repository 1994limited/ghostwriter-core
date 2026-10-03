<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Preview;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Everything the locator looks for on a rendered preview, in reading
 * order, keyed by what the markers carry. Sent to the locator as JSON
 * (toArray()).
 *
 * @implements IteratorAggregate<int, MappedBlock>
 */
final class BlockMap implements Countable, IteratorAggregate
{
    /** @var array<string, MappedBlock> */
    private readonly array $byKey;

    /**
     * @param  list<MappedBlock>  $blocks
     */
    public function __construct(public readonly array $blocks = [])
    {
        $byKey = [];

        foreach ($blocks as $block) {
            $byKey[$block->key] = $block;
        }

        $this->byKey = $byKey;
    }

    public function get(string $key): ?MappedBlock
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }

    /**
     * The innermost block, field or section that shows a unit, if any.
     */
    public function forUnit(string $unitId): ?MappedBlock
    {
        $found = null;
        $deepest = -1;

        foreach ($this->blocks as $block) {
            if (in_array($unitId, $block->units, true) && $this->depth($block) > $deepest) {
                $found = $block;
                $deepest = $this->depth($block);
            }
        }

        return $found;
    }

    /** How many blocks, fields or sections it is inside. */
    public function depth(MappedBlock $block): int
    {
        $depth = 0;
        $at = $block;

        while ($at->parent !== null && $depth < 64) {
            $at = $this->get($at->parent);

            if ($at === null) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    /**
     * @return list<MappedBlock>
     */
    public function children(?string $parent): array
    {
        return array_values(array_filter($this->blocks, fn (MappedBlock $block) => $block->parent === $parent));
    }

    public function count(): int
    {
        return count($this->blocks);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->blocks);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (MappedBlock $block) => $block->toArray(), $this->blocks);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(array_values(array_map(fn (array $block) => MappedBlock::fromArray($block), array_filter($array, 'is_array'))));
    }
}
