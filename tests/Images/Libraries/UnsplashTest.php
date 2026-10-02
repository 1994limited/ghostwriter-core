<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Unsplash;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

final class UnsplashTest extends ImagesTestCase
{
    use LibraryContract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials->set('unsplash', 'secret-unsplash-key');
    }

    public function test_it_needs_a_key_and_must_be_credited(): void
    {
        $this->assertTrue($this->library()->available());
        $this->assertTrue($this->library()->capabilities()->creditRequired);
        $this->assertTrue($this->library()->capabilities()->mayRank);

        $this->credentials->set('unsplash', ' ');
        $this->assertFalse($this->library()->available());
    }

    public function test_a_later_page_is_asked_for_by_number(): void
    {
        $this->routeSearch();

        $this->library()->search(new SearchQuery('soup', page: 2));

        parse_str($this->http->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame('2', $query['page'] ?? null);
    }

    protected function library(): PhotoLibrary
    {
        return new Unsplash($this->http, $this->credentials);
    }

    protected function routeSearch(): void
    {
        $this->route('https://api.unsplash.com/search/photos', ['results' => [$this->unsplash('Ab1-x_Y'), $this->unsplash('Cd2')]]);
    }

    protected function routePhoto(string $id): void
    {
        $this->route("https://api.unsplash.com/photos/{$id}", $this->unsplash($id));
    }

    protected function contractId(): string
    {
        return 'Ab1-x_Y';
    }

    protected function contractKey(): string
    {
        return 'secret-unsplash-key';
    }

    /**
     * @return array<string, mixed>
     */
    private function unsplash(string $id): array
    {
        return [
            'id' => $id,
            'alt_description' => 'a bowl of soup',
            'urls' => ['small' => "https://images.unsplash.com/{$id}?w=400", 'regular' => "https://images.unsplash.com/{$id}?w=1080", 'raw' => "https://images.unsplash.com/{$id}"],
            'links' => ['html' => "https://unsplash.com/photos/{$id}"],
            'user' => ['name' => 'Ann Lee'],
        ];
    }
}
