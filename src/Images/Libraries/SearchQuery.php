<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;

/**
 * One search of one library: the words, the shape wanted, which page of
 * results and how many on it, whether editorial-only images may come back
 * (off by default: most pages are commercial use), and any filters a
 * library understands (`people`, `collection`…), which others ignore.
 */
final class SearchQuery
{
    public readonly string $term;

    public readonly Shape $shape;

    public readonly int $page;

    public readonly int $perPage;

    /**
     * @param  Shape|string  $shape  A Shape, or landscape, portrait or square.
     * @param  array<string, scalar>  $filters
     */
    public function __construct(
        string $term,
        Shape|string $shape = Shape::Landscape,
        int $page = 1,
        int $perPage = 9,
        public readonly bool $editorial = false,
        public readonly array $filters = [],
    ) {
        $this->term = trim($term);
        $this->shape = PhotoContext::shape($shape);
        $this->page = max(1, $page);
        $this->perPage = max(1, min(100, $perPage));
    }

    /** The same search with other words, such as a shorter retry. */
    public function withTerm(string $term): self
    {
        return new self($term, $this->shape, $this->page, $this->perPage, $this->editorial, $this->filters);
    }
}
