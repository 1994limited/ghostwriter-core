<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Thumbnails;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * Openverse, which needs no key, searched for public-domain and CC0 work
 * only, so nothing found there carries a condition the site must meet. A
 * site can switch it off.
 *
 * Its thumbnails come through its own proxy, which can't reach every
 * source, so twice as many are asked for and only those whose thumbnails
 * load are kept. Only the thumbnails are checked: a result without one
 * would mean fetching its full original from its source.
 */
final class Openverse extends FreeLibrary
{
    /** @var bool|Closure(): bool */
    private readonly bool|Closure $enabled;

    private readonly Thumbnails $thumbnails;

    /**
     * @param  bool|callable(): bool  $enabled  A value, or a callable read each time (a setting that can change).
     * @param  Thumbnails|null  $thumbnails  @internal StockSearch shares its thumbnail cache.
     */
    public function __construct(HttpClients $http, bool|callable $enabled = true, ?Thumbnails $thumbnails = null)
    {
        parent::__construct($http);
        $this->enabled = is_bool($enabled) ? $enabled : Closure::fromCallable($enabled);
        $this->thumbnails = $thumbnails ?? new Thumbnails(new Downloader($http));
    }

    public function id(): string
    {
        return 'openverse';
    }

    public function label(): string
    {
        return 'Openverse';
    }

    public function available(): bool
    {
        return is_bool($this->enabled) ? $this->enabled : (bool) ($this->enabled)();
    }

    public function search(SearchQuery $query): array
    {
        $data = $this->downloader->json('https://api.openverse.org/v1/images/', [
            'q' => $query->term,
            'page_size' => $query->perPage * 2,
            'license' => 'cc0,pdm',
            'extension' => 'jpg,png',
            'aspect_ratio' => match ($query->shape) {
                Shape::Landscape => 'wide',
                Shape::Portrait => 'tall',
                Shape::Square => 'square',
            },
            'mature' => 'false',
        ] + ($query->page > 1 ? ['page' => $query->page] : []), label: 'Openverse');

        $photos = $this->each($data['results'] ?? [], fn (array $photo) => $this->result($photo));
        $thumbs = $this->thumbnails->get(array_map(fn (Photo $photo) => $photo->thumb, $photos));
        $loaded = array_values(array_filter($photos, fn (Photo $photo, int $i) => $thumbs[$i] !== null, ARRAY_FILTER_USE_BOTH));

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), array_slice($loaded, 0, $query->perPage));
    }

    protected function lookup(string $id): array
    {
        $data = $this->downloader->json("https://api.openverse.org/v1/images/{$id}/", label: 'Openverse');

        if (! in_array($data['license'] ?? null, ['cc0', 'pdm'], true)) {
            throw new PhotoUnavailable('That photograph is not free of conditions.');
        }

        return [$this->result($data) ?? throw new PhotoUnavailable('That photograph could not be found.'), $data];
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function result(array $photo): ?Photo
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
}
