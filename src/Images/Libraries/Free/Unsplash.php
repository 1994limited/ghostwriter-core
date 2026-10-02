<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * Unsplash, searched when the site has an access key (Credentials
 * `unsplash`). A download is reported to Unsplash, as its terms ask, and
 * the file is asked for at 2,400 pixels wide as a JPEG.
 *
 * Its photos are not judged by a model: Unsplash's API Terms (§12) send
 * any use "in connection with" machine learning or AI to its data
 * licensing, and ranking is at least that. They are listed after the
 * judged ones, in Unsplash's order, until Unsplash or counsel says
 * otherwise.
 */
final class Unsplash extends FreeLibrary
{
    public function __construct(HttpClients $http, private readonly Credentials $credentials)
    {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'unsplash';
    }

    public function label(): string
    {
        return 'Unsplash';
    }

    public function available(): bool
    {
        return $this->credentials->key('unsplash') !== null;
    }

    public function search(SearchQuery $query): array
    {
        $data = $this->downloader->json('https://api.unsplash.com/search/photos', [
            'query' => $query->term,
            'per_page' => $query->perPage,
            'orientation' => $query->shape === Shape::Square ? 'squarish' : $query->shape->value,
            'content_filter' => 'high',
        ] + ($query->page > 1 ? ['page' => $query->page] : []), $this->headers(), label: 'Unsplash');

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), $this->each($data['results'] ?? [], fn (array $photo) => $this->result($photo)));
    }

    protected function creditRequired(): bool
    {
        return true;
    }

    protected function mayRank(): bool
    {
        return false;
    }

    protected function lookup(string $id): array
    {
        $data = $this->downloader->json("https://api.unsplash.com/photos/{$id}", headers: $this->headers(), label: 'Unsplash');

        return [$this->result($data) ?? throw new PhotoUnavailable('That photograph could not be found.'), $data];
    }

    protected function fileAddress(Photo $photo, array $data): array
    {
        // Unsplash asks to be told when a photograph is actually used.
        if (($location = $this->string($data, 'links', 'download_location')) !== null) {
            $this->downloader->ping($location, $this->headers());
        }

        $raw = (string) $this->string($data, 'urls', 'raw');

        return [$raw === '' ? '' : $raw.(str_contains($raw, '?') ? '&' : '?').'w=2400&fm=jpg&q=82', []];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Client-ID '.$this->credentials->key('unsplash'), 'Accept-Version' => 'v1'];
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function result(array $photo): ?Photo
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
}
