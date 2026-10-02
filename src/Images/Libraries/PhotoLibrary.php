<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * One photo library: Unsplash, Pexels, Pixabay and Openverse today (in
 * Libraries\Free), paid libraries later. StockSearch holds a set of them
 * and merges their results.
 *
 * Every address is fetched https only (Downloader), and no message a
 * library throws ever holds a key, a token or a signed address.
 */
interface PhotoLibrary
{
    /** Short and lower case, and a Photo's `source`: 'unsplash', 'getty'. */
    public function id(): string;

    /** As the dialog shows it: 'Unsplash', 'Getty Images'. */
    public function label(): string;

    public function capabilities(): Capabilities;

    /** Whether it can be searched now: its keys are set (and it is connected or switched on, where that applies). */
    public function available(): bool;

    /**
     * One page of results, in the library's own order.
     *
     * @return array<int, Photo> Each with `term` set to the query's words.
     *
     * @throws PhotoUnavailable when the library fails or refuses.
     */
    public function search(SearchQuery $query): array;

    /**
     * The photo looked up again by ID, never from what a browser sent.
     *
     * @throws PhotoUnavailable for an ID that isn't this library's or a photo that has gone.
     */
    public function photo(string $id): Photo;

    /**
     * The final file, to save as the asset ("Use this"). Only a free
     * library hands one over this way; a paid one refuses.
     *
     * @throws PhotoUnavailable
     */
    public function fetch(string $id): PhotoFile;
}
