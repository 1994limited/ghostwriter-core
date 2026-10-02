<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Finds free-to-use photographs, for sites with no image model to call or
 * that would rather use a real photograph.
 *
 * Unsplash, Pexels and Pixabay are searched when the site has a (free) API
 * key for them, read through Credentials (`unsplash`, `pexels`, `pixabay`).
 * Openverse needs no key and is searched for public-domain and CC0 work
 * only, so nothing found there carries a condition the site must meet; a
 * site can switch it off.
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
    /** Every library, best first. */
    public const SOURCES = ['unsplash', 'pexels', 'pixabay', 'openverse'];

    public const LABELS = ['unsplash' => 'Unsplash', 'pexels' => 'Pexels', 'pixabay' => 'Pixabay', 'openverse' => 'Openverse'];

    /** Results asked of each library per search. */
    public const PER_SOURCE = 9;

    /** The largest photograph that will be downloaded. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** The largest thumbnail that will be downloaded. */
    public const MAX_THUMB_BYTES = 2 * 1024 * 1024;

    /** How many thumbnails are remembered, so a check and a ranking fetch each once. */
    private const THUMB_CACHE = 120;

    private readonly Downloader $downloader;

    private readonly LoggerInterface $logger;

    /** @var bool|Closure(): bool */
    private readonly bool|Closure $openverse;

    /** @var array<string, array{content: string, mime: string}|null> Thumbnails by address. */
    private array $thumbs = [];

    /**
     * @param  bool|callable(): bool  $openverse  Whether to search Openverse: a value, or a callable read on each search (a setting that can change).
     */
    public function __construct(
        HttpClients $http,
        private readonly Credentials $credentials,
        bool|callable $openverse = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->downloader = new Downloader($http);
        $this->logger = $logger ?? new NullLogger;
        $this->openverse = is_bool($openverse) ? $openverse : Closure::fromCallable($openverse);
    }

    /**
     * @return array<int, string> The libraries that can be searched, best first.
     */
    public function sources(): array
    {
        return array_values(array_filter(self::SOURCES, fn (string $source) => $this->available($source)));
    }

    public function available(string $source): bool
    {
        return match ($source) {
            'unsplash', 'pexels', 'pixabay' => $this->credentials->key($source) !== null,
            'openverse' => is_bool($this->openverse) ? $this->openverse : (bool) ($this->openverse)(),
            default => false,
        };
    }

    /**
     * Search every available library, each in turn. One library failing
     * doesn't hide the others; it is logged at warning.
     *
     * The smaller libraries match every word, so a long search can find
     * nothing; then its first two words, usually the subject, are tried.
     *
     * @param  Shape|string  $shape  A Shape, or landscape, portrait or square.
     * @return array<int, Photo> Each with `term` set to $query.
     */
    public function search(string $query, Shape|string $shape = Shape::Landscape, int $perSource = self::PER_SOURCE): array
    {
        $query = trim($query);
        $shape = PhotoContext::shape($shape);

        if ($query === '') {
            return [];
        }

        $results = $this->searchAll($query, $shape, $perSource);
        $words = preg_split('/\s+/u', $query) ?: [];

        if ($results === [] && count($words) > 2) {
            $results = $this->searchAll(implode(' ', array_slice($words, 0, 2)), $shape, $perSource);
        }

        return array_map(fn (Photo $photo) => $photo->withTerm($query), $results);
    }

    /**
     * Download one photograph. Its address is looked up again from the
     * library by ID, never taken from a browser; for Unsplash, the download
     * is reported to Unsplash, as its terms ask.
     *
     * @throws PhotoUnavailable for an unknown photo or library, an address that isn't https, a file over MAX_BYTES or one that isn't a JPEG, PNG or WebP.
     */
    public function fetch(string $source, string $id): PhotoFile
    {
        if (! $this->available($source) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new PhotoUnavailable('That photograph could not be found.');
        }

        [$photo, $file, $headers] = match ($source) {
            'unsplash' => $this->unsplashPhoto($id),
            'pexels' => $this->pexelsPhoto($id),
            'pixabay' => $this->pixabayPhoto($id),
            default => $this->openversePhoto($id),
        };

        if ($file === '' || ! $this->downloader->secure($file)) {
            throw new PhotoUnavailable('That photograph has no secure download address.');
        }

        $image = $this->downloader->image($file, self::MAX_BYTES, 60, $headers);

        return new PhotoFile($image['content'], $image['mime'], Downloader::IMAGE_TYPES[$image['mime']], $photo);
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
        $this->loadThumbs(array_map(fn (Photo $photo) => $photo->thumb, $photos));

        return array_map(fn (Photo $photo) => $this->thumbs[$photo->thumb]['content'] ?? null, $photos);
    }

    /**
     * @return array<int, Photo>
     */
    private function searchAll(string $query, Shape $shape, int $perSource): array
    {
        $results = [];

        foreach ($this->sources() as $source) {
            try {
                array_push($results, ...match ($source) {
                    'unsplash' => $this->unsplash($query, $shape, $perSource),
                    'pexels' => $this->pexels($query, $shape, $perSource),
                    'pixabay' => $this->pixabay($query, $shape, $perSource),
                    default => $this->openverse($query, $shape, $perSource),
                });
            } catch (Throwable $exception) {
                $this->logger->warning("Ghostwriter: searching {$source} failed: {$exception->getMessage()}", ['source' => $source]);
            }
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function unsplashHeaders(): array
    {
        return ['Authorization' => 'Client-ID '.$this->credentials->key('unsplash'), 'Accept-Version' => 'v1'];
    }

    /**
     * @return array<int, Photo>
     */
    private function unsplash(string $query, Shape $shape, int $perSource): array
    {
        $data = $this->downloader->json('https://api.unsplash.com/search/photos', [
            'query' => $query,
            'per_page' => $perSource,
            'orientation' => $shape === Shape::Square ? 'squarish' : $shape->value,
            'content_filter' => 'high',
        ], $this->unsplashHeaders(), label: 'Unsplash');

        return $this->each($data['results'] ?? [], fn (array $photo) => $this->unsplashResult($photo));
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function unsplashResult(array $photo): ?Photo
    {
        $thumb = $this->string($photo, 'urls', 'small');

        if ($this->string($photo, 'id') === null || $thumb === null) {
            return null;
        }

        $caption = $this->string($photo, 'description');

        return new Photo(
            source: 'unsplash',
            id: (string) $this->string($photo, 'id'),
            thumb: $thumb,
            credit: ($this->string($photo, 'user', 'name') ?? 'Unknown').' on Unsplash',
            creditUrl: $this->string($photo, 'links', 'html'),
            licence: 'Unsplash licence',
            // A photographer's caption makes a title when it reads as one.
            title: $caption !== null && ! str_contains($caption, '#') && mb_strlen($caption) <= 120 ? $caption : null,
            description: $this->string($photo, 'alt_description') ?? $caption,
            tags: $this->tagNames($photo['tags'] ?? []),
            width: $this->int($photo, 'width'),
            height: $this->int($photo, 'height'),
            url: $this->string($photo, 'urls', 'regular'),
        );
    }

    /**
     * @return array{0: Photo, 1: string, 2: array<string, string>}
     */
    private function unsplashPhoto(string $id): array
    {
        $data = $this->downloader->json("https://api.unsplash.com/photos/{$id}", headers: $this->unsplashHeaders(), label: 'Unsplash');
        $photo = $this->unsplashResult($data) ?? throw new PhotoUnavailable('That photograph could not be found.');

        // Unsplash asks to be told when a photograph is actually used.
        if (($location = $this->string($data, 'links', 'download_location')) !== null) {
            $this->downloader->ping($location, $this->unsplashHeaders());
        }

        $raw = (string) $this->string($data, 'urls', 'raw');

        return [$photo, $raw === '' ? '' : $raw.(str_contains($raw, '?') ? '&' : '?').'w=2400&fm=jpg&q=82', []];
    }

    /**
     * @return array<int, Photo>
     */
    private function pexels(string $query, Shape $shape, int $perSource): array
    {
        $data = $this->downloader->json('https://api.pexels.com/v1/search', [
            'query' => $query,
            'per_page' => $perSource,
            'orientation' => $shape->value,
        ], ['Authorization' => (string) $this->credentials->key('pexels')], label: 'Pexels');

        return $this->each($data['photos'] ?? [], fn (array $photo) => $this->pexelsResult($photo));
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function pexelsResult(array $photo): ?Photo
    {
        $thumb = $this->string($photo, 'src', 'medium');

        if ($this->string($photo, 'id') === null || $thumb === null) {
            return null;
        }

        $page = $this->string($photo, 'url');

        return new Photo(
            source: 'pexels',
            id: (string) $this->string($photo, 'id'),
            thumb: $thumb,
            credit: ($this->string($photo, 'photographer') ?? 'Unknown').' on Pexels',
            creditUrl: $page,
            licence: 'Pexels licence',
            // Pexels has no title, but its addresses carry one: /photo/brown-rocks-at-dusk-2014422/.
            title: $page !== null && preg_match('#/photo/([a-z0-9-]+?)-\d+/?$#i', $page, $m) ? Slug::upperFirst(str_replace('-', ' ', $m[1])) : null,
            description: $this->string($photo, 'alt'),
            width: $this->int($photo, 'width'),
            height: $this->int($photo, 'height'),
            url: $this->string($photo, 'src', 'large2x') ?? $this->string($photo, 'src', 'original'),
        );
    }

    /**
     * @return array{0: Photo, 1: string, 2: array<string, string>}
     */
    private function pexelsPhoto(string $id): array
    {
        $data = $this->downloader->json("https://api.pexels.com/v1/photos/{$id}", headers: ['Authorization' => (string) $this->credentials->key('pexels')], label: 'Pexels');
        $photo = $this->pexelsResult($data) ?? throw new PhotoUnavailable('That photograph could not be found.');

        return [$photo, (string) $photo->url, []];
    }

    /**
     * @return array<int, Photo>
     */
    private function pixabay(string $query, Shape $shape, int $perSource): array
    {
        $data = $this->downloader->json('https://pixabay.com/api/', [
            'key' => (string) $this->credentials->key('pixabay'),
            'q' => $query,
            'image_type' => 'photo',
            'per_page' => max(3, $perSource),
            'orientation' => match ($shape) {
                Shape::Portrait => 'vertical',
                Shape::Landscape => 'horizontal',
                Shape::Square => 'all',
            },
            'safesearch' => 'true',
        ], label: 'Pixabay');

        return array_slice($this->each($data['hits'] ?? [], fn (array $photo) => $this->pixabayResult($photo)), 0, $perSource);
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function pixabayResult(array $photo): ?Photo
    {
        $thumb = $this->string($photo, 'webformatURL');

        if ($this->string($photo, 'id') === null || $thumb === null) {
            return null;
        }

        return new Photo(
            source: 'pixabay',
            id: (string) $this->string($photo, 'id'),
            thumb: $thumb,
            credit: ($this->string($photo, 'user') ?? 'Unknown').' on Pixabay',
            creditUrl: $this->string($photo, 'pageURL'),
            licence: 'Pixabay licence',
            tags: $this->tagNames(explode(',', (string) $this->string($photo, 'tags'))),
            width: $this->int($photo, 'imageWidth'),
            height: $this->int($photo, 'imageHeight'),
            url: $this->string($photo, 'largeImageURL') ?? $thumb,
        );
    }

    /**
     * @return array{0: Photo, 1: string, 2: array<string, string>}
     */
    private function pixabayPhoto(string $id): array
    {
        $data = $this->downloader->json('https://pixabay.com/api/', ['key' => (string) $this->credentials->key('pixabay'), 'id' => $id], label: 'Pixabay');
        $hit = is_array($data['hits'][0] ?? null) ? $data['hits'][0] : [];
        $photo = $this->pixabayResult($hit) ?? throw new PhotoUnavailable('That photograph could not be found.');

        return [$photo, (string) $photo->url, []];
    }

    /**
     * Openverse's thumbnails come through its own proxy, which can't reach
     * every source, so twice as many are asked for and only those whose
     * thumbnails load are kept. Only the thumbnails are checked: a result
     * without one would mean fetching its full original from its source.
     *
     * @return array<int, Photo>
     */
    private function openverse(string $query, Shape $shape, int $perSource): array
    {
        $data = $this->downloader->json('https://api.openverse.org/v1/images/', [
            'q' => $query,
            'page_size' => $perSource * 2,
            'license' => 'cc0,pdm',
            'extension' => 'jpg,png',
            'aspect_ratio' => match ($shape) {
                Shape::Landscape => 'wide',
                Shape::Portrait => 'tall',
                Shape::Square => 'square',
            },
            'mature' => 'false',
        ], label: 'Openverse');

        $photos = $this->each($data['results'] ?? [], fn (array $photo) => $this->openverseResult($photo));
        $thumbs = $this->thumbnails($photos);

        return array_slice(array_values(array_filter($photos, fn (Photo $photo, int $i) => $thumbs[$i] !== null, ARRAY_FILTER_USE_BOTH)), 0, $perSource);
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function openverseResult(array $photo): ?Photo
    {
        $thumb = $this->string($photo, 'thumbnail');
        $licence = strtolower((string) $this->string($photo, 'license'));

        if ($this->string($photo, 'id') === null || $thumb === null || ! str_starts_with($thumb, 'https://') || ! in_array($licence, ['cc0', 'pdm'], true)) {
            return null;
        }

        return new Photo(
            source: 'openverse',
            id: (string) $this->string($photo, 'id'),
            thumb: $thumb,
            credit: ($this->string($photo, 'creator') ?? 'Unknown').' via Openverse',
            creditUrl: $this->string($photo, 'foreign_landing_url'),
            licence: $licence === 'pdm' ? 'Public domain' : 'CC0',
            title: $this->cleanTitle($this->string($photo, 'title')),
            tags: $this->tagNames($photo['tags'] ?? []),
            width: $this->int($photo, 'width'),
            height: $this->int($photo, 'height'),
            url: $this->string($photo, 'url'),
        );
    }

    /**
     * @return array{0: Photo, 1: string, 2: array<string, string>}
     */
    private function openversePhoto(string $id): array
    {
        $data = $this->downloader->json("https://api.openverse.org/v1/images/{$id}/", label: 'Openverse');

        if (! in_array($data['license'] ?? null, ['cc0', 'pdm'], true)) {
            throw new PhotoUnavailable('That photograph is not free of conditions.');
        }

        $photo = $this->openverseResult($data) ?? throw new PhotoUnavailable('That photograph could not be found.');

        return [$photo, (string) $photo->url, []];
    }

    /**
     * A file name or camera code is not a title: "IMG_2034.JPG", "DSC01234",
     * "File:Old bridge.jpg" (which becomes "Old bridge").
     */
    private function cleanTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = (string) preg_replace('/^file:\s*/i', '', trim($title));
        $title = trim((string) preg_replace('/\.(jpe?g|png|webp|gif|tiff?|heic)$/i', '', $title));

        if ($title === '' || preg_match('/^[a-z_\- ]{0,6}\d[\d_\- .]*$/i', $title)) {
            return null;
        }

        return $title;
    }

    /**
     * Tag names from a list of strings or of {name: ...} (Unsplash,
     * Openverse); machine-made Openverse tags (with an accuracy) go last.
     *
     * @return array<int, string>
     */
    private function tagNames(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $people = [];
        $machine = [];

        foreach ($tags as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? $tag['title'] ?? null) : $tag;
            $name = is_scalar($name) ? Slug::clip((string) $name, 40) : '';

            if ($name !== '') {
                is_array($tag) && isset($tag['accuracy']) ? $machine[] = $name : $people[] = $name;
            }
        }

        return array_slice(array_values(array_unique([...$people, ...$machine])), 0, 12);
    }

    /**
     * @param  callable(array<mixed>): ?Photo  $map
     * @return array<int, Photo>
     */
    private function each(mixed $items, callable $map): array
    {
        $photos = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && ($photo = $map($item)) !== null) {
                $photos[] = $photo;
            }
        }

        return $photos;
    }

    /**
     * A non-empty string at a path in decoded JSON, trimmed.
     *
     * @param  array<mixed>  $data
     */
    private function string(array $data, string ...$path): ?string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  array<int|string, string>  $urls
     */
    private function loadThumbs(array $urls): void
    {
        $missing = array_values(array_unique(array_filter($urls, fn (string $url) => ! array_key_exists($url, $this->thumbs))));

        if ($missing === []) {
            return;
        }

        foreach ($this->downloader->images($missing, self::MAX_THUMB_BYTES) as $i => $thumb) {
            $this->thumbs[$missing[$i]] = $thumb;
        }

        if (count($this->thumbs) > self::THUMB_CACHE) {
            $this->thumbs = array_slice($this->thumbs, -self::THUMB_CACHE, null, true);
        }
    }
}
