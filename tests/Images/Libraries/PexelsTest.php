<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Pexels;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

final class PexelsTest extends ImagesTestCase
{
    use LibraryContract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credentials->set('pexels', 'secret-pexels-key');
    }

    public function test_the_key_goes_in_the_authorization_header(): void
    {
        $this->routeSearch();

        $this->library()->search(new SearchQuery('rocks'));

        $this->assertSame('secret-pexels-key', $this->http->requests[0]->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('secret-pexels-key', (string) $this->http->requests[0]->getUri());
    }

    protected function library(): PhotoLibrary
    {
        return new Pexels($this->http, $this->credentials);
    }

    protected function routeSearch(): void
    {
        $this->route('https://api.pexels.com/v1/search', ['photos' => [$this->pexels('2014422'), $this->pexels('77')]]);
    }

    protected function routePhoto(string $id): void
    {
        $this->route("https://api.pexels.com/v1/photos/{$id}", $this->pexels($id));
    }

    protected function contractId(): string
    {
        return '2014422';
    }

    protected function contractKey(): string
    {
        return 'secret-pexels-key';
    }

    /**
     * @return array<string, mixed>
     */
    private function pexels(string $id): array
    {
        return [
            'id' => (int) $id,
            'url' => "https://www.pexels.com/photo/brown-rocks-{$id}/",
            'photographer' => 'Joe',
            'alt' => 'Brown rocks',
            'src' => ['medium' => "https://images.pexels.com/{$id}-m.jpg", 'large2x' => "https://images.pexels.com/{$id}-l.jpg"],
        ];
    }
}
