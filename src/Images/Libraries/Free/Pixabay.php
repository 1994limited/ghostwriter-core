<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * Pixabay, searched when the site has an API key (Credentials `pixabay`).
 * Its key goes in the query string, so it is never put in a message.
 */
final class Pixabay extends FreeLibrary
{
    public function __construct(HttpClients $http, private readonly Credentials $credentials)
    {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'pixabay';
    }

    public function label(): string
    {
        return 'Pixabay';
    }

    public function available(): bool
    {
        return $this->credentials->key('pixabay') !== null;
    }

    public function search(SearchQuery $query): array
    {
        $data = $this->downloader->json('https://pixabay.com/api/', [
            'key' => (string) $this->credentials->key('pixabay'),
            'q' => $query->term,
            'image_type' => 'photo',
            // Pixabay asks for at least three.
            'per_page' => max(3, $query->perPage),
            'orientation' => match ($query->shape) {
                Shape::Portrait => 'vertical',
                Shape::Landscape => 'horizontal',
                Shape::Square => 'all',
            },
            'safesearch' => 'true',
        ] + ($query->page > 1 ? ['page' => $query->page] : []), label: 'Pixabay');

        $photos = array_slice($this->each($data['hits'] ?? [], fn (array $photo) => $this->result($photo)), 0, $query->perPage);

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), $photos);
    }

    protected function lookup(string $id): array
    {
        $data = $this->downloader->json('https://pixabay.com/api/', ['key' => (string) $this->credentials->key('pixabay'), 'id' => $id], label: 'Pixabay');
        $hit = is_array($data['hits'][0] ?? null) ? $data['hits'][0] : [];

        return [$this->result($hit) ?? throw new PhotoUnavailable('That photograph could not be found.'), $hit];
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function result(array $photo): ?Photo
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
}
