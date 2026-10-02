<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * Pexels, searched when the site has an API key (Credentials `pexels`).
 */
final class Pexels extends FreeLibrary
{
    public function __construct(HttpClients $http, private readonly Credentials $credentials)
    {
        parent::__construct($http);
    }

    public function id(): string
    {
        return 'pexels';
    }

    public function label(): string
    {
        return 'Pexels';
    }

    public function available(): bool
    {
        return $this->credentials->key('pexels') !== null;
    }

    public function search(SearchQuery $query): array
    {
        $data = $this->downloader->json('https://api.pexels.com/v1/search', [
            'query' => $query->term,
            'per_page' => $query->perPage,
            'orientation' => $query->shape->value,
        ] + ($query->page > 1 ? ['page' => $query->page] : []), $this->headers(), label: 'Pexels');

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), $this->each($data['photos'] ?? [], fn (array $photo) => $this->result($photo)));
    }

    protected function creditRequired(): bool
    {
        return true;
    }

    protected function lookup(string $id): array
    {
        $data = $this->downloader->json("https://api.pexels.com/v1/photos/{$id}", headers: $this->headers(), label: 'Pexels');

        return [$this->result($data) ?? throw new PhotoUnavailable('That photograph could not be found.'), $data];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => (string) $this->credentials->key('pexels')];
    }

    /**
     * @param  array<mixed>  $photo
     */
    private function result(array $photo): ?Photo
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
}
