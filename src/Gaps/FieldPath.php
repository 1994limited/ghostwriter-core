<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use InvalidArgumentException;

/**
 * Where a value sits in an entry, in EntryData's terms: field handles, and
 * for page builders and rows the block or row it is in. Blocks are named
 * by ID where they have one, so a reorder doesn't move a gap.
 *
 *     new FieldPath(['page_builder', new BlockRef('a1b2', 1, 'hero'), 'intro'])
 *     // toString(): "page_builder/#a1b2/intro"; dotted(): "page_builder.1.intro"
 */
final class FieldPath
{
    /** @var list<string|BlockRef> */
    public readonly array $segments;

    /**
     * @param  array<int, string|BlockRef>  $segments
     */
    public function __construct(array $segments)
    {
        if ($segments === []) {
            throw new InvalidArgumentException('A field path needs at least a handle.');
        }

        $this->segments = array_values($segments);
    }

    public static function of(string $handle): self
    {
        return new self([$handle]);
    }

    public function with(string|BlockRef $segment): self
    {
        return new self([...$this->segments, $segment]);
    }

    /** "page_builder/#a1b2/intro", or "page_builder/1/intro" for a block with no ID. */
    public function toString(): string
    {
        return implode('/', array_map(fn (string|BlockRef $segment) => $segment instanceof BlockRef ? $segment->toString() : $segment, $this->segments));
    }

    /** By position, as a form addresses a value: "page_builder.1.intro". */
    public function dotted(): string
    {
        return implode('.', array_map(fn (string|BlockRef $segment) => $segment instanceof BlockRef ? (string) $segment->index : $segment, $this->segments));
    }

    /** The top-level field's handle. */
    public function handle(): string
    {
        $first = $this->segments[0];

        return $first instanceof BlockRef ? $first->toString() : $first;
    }

    /** The handle of the field the value is in: the last handle. */
    public function field(): string
    {
        for ($i = count($this->segments) - 1; $i >= 0; $i--) {
            if (is_string($this->segments[$i])) {
                return $this->segments[$i];
            }
        }

        return $this->handle();
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    /**
     * From toString(). A block segment comes back with its ID (`#a1b2`) or
     * its position (`1`), and no type.
     */
    public static function parse(string $path): self
    {
        $segments = [];

        foreach (explode('/', trim($path, '/')) as $part) {
            if (str_starts_with($part, '#') && strlen($part) > 1) {
                $segments[] = new BlockRef(substr($part, 1), 0);
            } elseif ($part !== '' && ctype_digit($part)) {
                $segments[] = new BlockRef(null, (int) $part);
            } elseif ($part !== '') {
                $segments[] = $part;
            }
        }

        return new self($segments);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
