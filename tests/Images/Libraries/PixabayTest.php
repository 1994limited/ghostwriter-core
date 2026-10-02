<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Pixabay;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

final class PixabayTest extends ImagesTestCase
{
    use LibraryContract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials->set('pixabay', 'secret-pixabay-key');
    }

    public function test_at_least_three_are_asked_for_and_no_more_than_wanted_are_kept(): void
    {
        $this->routeSearch();

        $photos = $this->library()->search(new SearchQuery('tree', perPage: 1));

        $this->assertCount(1, $photos);
        parse_str($this->http->requests[0]->getUri()->getQuery(), $query);
        $this->assertSame('3', $query['per_page']);
    }

    protected function library(): PhotoLibrary
    {
        return new Pixabay($this->http, $this->credentials);
    }

    protected function routeSearch(): void
    {
        $this->route('https://pixabay.com/api/?key=secret-pixabay-key&q=', ['hits' => [$this->pixabay('736885'), $this->pixabay('12')]]);
    }

    protected function routePhoto(string $id): void
    {
        $this->route("https://pixabay.com/api/?key=secret-pixabay-key&id={$id}", ['hits' => [$this->pixabay($id)]]);
    }

    protected function contractId(): string
    {
        return '736885';
    }

    protected function contractKey(): string
    {
        return 'secret-pixabay-key';
    }

    /**
     * @return array<string, mixed>
     */
    private function pixabay(string $id): array
    {
        return [
            'id' => (int) $id,
            'pageURL' => "https://pixabay.com/photos/tree-{$id}/",
            'tags' => 'tree, sunset',
            'webformatURL' => "https://pixabay.com/get/{$id}_640.jpg",
            'largeImageURL' => "https://pixabay.com/get/{$id}_1280.jpg",
            'user' => 'Bess',
        ];
    }
}
