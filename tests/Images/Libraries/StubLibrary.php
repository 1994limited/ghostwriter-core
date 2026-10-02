<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * A library that answers every search with the same photos, for testing
 * how StockSearch and PhotoRanker treat a library by its capabilities.
 */
final class StubLibrary implements PhotoLibrary
{
    /** @var array<int, SearchQuery> */
    public array $searches = [];

    /**
     * @param  array<int, Photo>  $photos
     */
    public function __construct(
        private readonly string $id,
        private readonly Capabilities $capabilities,
        private readonly array $photos = [],
        public bool $available = true,
        private readonly string $label = 'Stub library',
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function search(SearchQuery $query): array
    {
        $this->searches[] = $query;

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), $this->photos);
    }

    public function photo(string $id): Photo
    {
        foreach ($this->photos as $photo) {
            if ($photo->id === $id) {
                return $photo;
            }
        }

        throw new PhotoUnavailable('That photograph could not be found.');
    }

    public function fetch(string $id): PhotoFile
    {
        if (! $this->capabilities->free) {
            throw new PhotoUnavailable('That photograph must be licensed first.');
        }

        return new PhotoFile('bytes', 'image/jpeg', 'jpg', $this->photo($id));
    }
}
