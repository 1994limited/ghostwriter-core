<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\FreeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Openverse;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Pexels;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Pixabay;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Unsplash;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Thumbnails;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Finds photographs in the site's photo libraries, for sites with no image
 * model to call or that would rather use a real photograph.
 *
 * It holds a set of PhotoLibrary objects and merges their results. The
 * four free ones are always there, best first:
 *
 * - Unsplash, Pexels and Pixabay, searched when the site has a (free) API
 *   key for them, read through Credentials (`unsplash`, `pexels`,
 *   `pixabay`).
 * - Openverse, which needs no key and is searched for public-domain and CC0
 *   work only, so nothing found there carries a condition the site must
 *   meet; a site can switch it off.
 *
 * Others (a paid library, a demo one) are passed in as `libraries`, and
 * come after them; one with the same ID as a free library replaces it.
 *
 * Every address is fetched https only, redirects included, and files are
 * read no further than their cap (Downloader). Openverse results are kept
 * only when Openverse's own thumbnail loads; full-size originals are never
 * fetched to check them.
 *
 *     $stock = new StockSearch($http, $credentials, openverse: fn () => $settings->openverse);
 *     $photos = $stock->search('mended pottery gold', Shape::Landscape);
 *     $file = $stock->fetch($photos[0]->source, $photos[0]->id);   // bytes, extension, and the Photo
 */
class StockSearch
{
    /** The free libraries, best first. */
    public const SOURCES = ['unsplash', 'pexels', 'pixabay', 'openverse'];

    public const LABELS = ['unsplash' => 'Unsplash', 'pexels' => 'Pexels', 'pixabay' => 'Pixabay', 'openverse' => 'Openverse'];

    /** Results asked of each library per search. */
    public const PER_SOURCE = 9;

    /** The largest photograph that will be downloaded. */
    public const MAX_BYTES = FreeLibrary::MAX_BYTES;

    /** The largest thumbnail that will be downloaded. */
    public const MAX_THUMB_BYTES = Thumbnails::MAX_BYTES;

    private readonly LoggerInterface $logger;

    private readonly Thumbnails $thumbs;

    /** @var array<string, PhotoLibrary> By ID, in order. */
    private readonly array $libraries;

    /**
     * @param  bool|callable(): bool  $openverse  Whether to search Openverse: a value, or a callable read on each search (a setting that can change).
     * @param  array<int, PhotoLibrary>  $libraries  More libraries, after the free ones; one with a free library's ID replaces it.
     */
    public function __construct(
        HttpClients $http,
        Credentials $credentials,
        bool|callable $openverse = true,
        ?LoggerInterface $logger = null,
        array $libraries = [],
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->thumbs = new Thumbnails(new Downloader($http));

        $all = [];

        foreach ([
            new Unsplash($http, $credentials),
            new Pexels($http, $credentials),
            new Pixabay($http, $credentials),
            new Openverse($http, $openverse, $this->thumbs),
            ...$libraries,
        ] as $library) {
            $all[$library->id()] = $library;
        }

        $this->libraries = $all;
    }

    /**
     * @return array<int, string> The libraries that can be searched, best first.
     */
    public function sources(): array
    {
        return array_values(array_filter(array_keys($this->libraries), fn (string $source) => $this->available($source)));
    }

    public function available(string $source): bool
    {
        return isset($this->libraries[$source]) && $this->libraries[$source]->available();
    }

    /**
     * Every library, available or not, by ID, best first.
     *
     * @return array<string, PhotoLibrary>
     */
    public function libraries(): array
    {
        return $this->libraries;
    }

    public function library(string $source): ?PhotoLibrary
    {
        return $this->libraries[$source] ?? null;
    }

    /** The library's name as the dialog shows it: "Unsplash", "Getty Images". */
    public function label(string $source): string
    {
        return isset($this->libraries[$source]) ? $this->libraries[$source]->label() : (self::LABELS[$source] ?? $source);
    }

    /**
     * Whether a model may be shown this photo (its thumbnail, title, tags):
     * only when its library's terms allow it (Capabilities::$mayRank). A
     * photo from a library this search doesn't hold may be shown only if
     * it isn't paid.
     */
    public function mayRank(Photo $photo): bool
    {
        $library = $this->libraries[$photo->source] ?? null;

        return $library !== null ? $library->capabilities()->mayRank : $photo->isFree();
    }

    /**
     * Search every available library, each in turn, or only those in
     * $sources. One library failing doesn't hide the others; it is logged
     * at warning.
     *
     * The smaller libraries match every word, so a long search can find
     * nothing; then its first two words, usually the subject, are tried.
     *
     * @param  Shape|string  $shape  A Shape, or landscape, portrait or square.
     * @param  array<int, string>|null  $sources  Library IDs; null for every available library.
     * @return array<int, Photo> Each with `term` set to $query.
     */
    public function search(string $query, Shape|string $shape = Shape::Landscape, int $perSource = self::PER_SOURCE, ?array $sources = null): array
    {
        $query = trim($query);
        $shape = PhotoContext::shape($shape);

        if ($query === '') {
            return [];
        }

        $sources = $sources === null ? $this->sources() : array_values(array_intersect($this->sources(), $sources));
        $results = $this->searchAll(new SearchQuery($query, $shape, perPage: $perSource), $sources);
        $words = preg_split('/\s+/u', $query) ?: [];

        if ($results === [] && count($words) > 2) {
            $results = $this->searchAll(new SearchQuery(implode(' ', array_slice($words, 0, 2)), $shape, perPage: $perSource), $sources);
        }

        return array_map(fn (Photo $photo) => $photo->withTerm($query), $results);
    }

    /**
     * Download one photograph from a free library. Its address is looked up
     * again from the library by ID, never taken from a browser; for
     * Unsplash, the download is reported to Unsplash, as its terms ask.
     *
     * @throws PhotoUnavailable for an unknown photo or library, an address that isn't https, a file over MAX_BYTES or one that isn't a JPEG, PNG or WebP.
     */
    public function fetch(string $source, string $id): PhotoFile
    {
        if (! $this->available($source)) {
            throw new PhotoUnavailable('That photograph could not be found.');
        }

        return $this->libraries[$source]->fetch($id);
    }

    /**
     * The photos' thumbnails, fetched side by side where the client allows,
     * each checked to be an image under MAX_THUMB_BYTES. Keys are kept; a
     * thumbnail that can't be had is null.
     *
     * @template K of array-key
     *
     * @param  array<K, Photo>  $photos
     * @return array<K, string|null> The bytes.
     */
    public function thumbnails(array $photos): array
    {
        return $this->thumbs->get(array_map(fn (Photo $photo) => $photo->thumb, $photos));
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<int, Photo>
     */
    private function searchAll(SearchQuery $query, array $sources): array
    {
        $results = [];

        foreach ($sources as $source) {
            try {
                array_push($results, ...$this->libraries[$source]->search($query));
            } catch (Throwable $exception) {
                $this->logger->warning("Ghostwriter: searching {$source} failed: {$exception->getMessage()}", ['source' => $source]);
            }
        }

        return $results;
    }
}
