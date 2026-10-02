<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use Psr\Http\Message\RequestInterface;

class StockSearchTest extends ImagesTestCase
{
    private const OPENVERSE_ID = '4bc43a04-ef46-4544-a0c1-63c63f56e276';

    public function test_only_libraries_with_a_key_are_searched_and_openverse_can_be_switched_off(): void
    {
        $this->assertSame(['openverse'], $this->stock()->sources());
        $this->assertSame([], $this->stock(openverse: false)->sources());

        $this->credentials->set('pexels', 'p-key')->set('unsplash', ' u-key ')->set('pixabay', '   ');
        $this->assertSame(['unsplash', 'pexels', 'openverse'], $this->stock()->sources());

        $on = false;
        $stock = new StockSearch($this->http, $this->credentials, function () use (&$on) {
            return $on;
        });
        $this->assertSame(['unsplash', 'pexels'], $stock->sources());
        $on = true;
        $this->assertSame(['unsplash', 'pexels', 'openverse'], $stock->sources(), 'The setting is read each time.');
    }

    public function test_unsplash_results_keep_the_librarys_own_words(): void
    {
        $this->credentials->set('unsplash', 'u-key');
        $this->route('https://api.unsplash.com/search/photos', ['results' => [$this->unsplashPhoto()]]);

        $photos = $this->stock(openverse: false)->search('soup bowl', Shape::Square);

        $this->assertCount(1, $photos);
        $photo = $photos[0];
        $this->assertSame('unsplash', $photo->source);
        $this->assertSame('Ab1-x_Y', $photo->id);
        $this->assertSame('https://images.unsplash.com/photo-1?w=400', $photo->thumb);
        $this->assertSame('Ann Lee on Unsplash', $photo->credit);
        $this->assertSame('https://unsplash.com/photos/Ab1-x_Y', $photo->creditUrl);
        $this->assertSame('Unsplash licence', $photo->licence);
        $this->assertSame("Grandma's kitchen", $photo->title);
        $this->assertSame('a bowl of soup on a wooden table', $photo->description);
        $this->assertSame(['soup', 'kitchen'], $photo->tags);
        $this->assertSame([4000, 3000], [$photo->width, $photo->height]);
        $this->assertSame('https://images.unsplash.com/photo-1?w=1080', $photo->url);
        $this->assertSame('soup bowl', $photo->term);
        $this->assertFalse($photo->picked);

        $request = $this->http->requests[0];
        $this->assertSame('Client-ID u-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('v1', $request->getHeaderLine('Accept-Version'));
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['query' => 'soup bowl', 'per_page' => '9', 'orientation' => 'squarish', 'content_filter' => 'high'], $query);
    }

    public function test_an_unsplash_caption_with_hashtags_is_not_a_title(): void
    {
        $this->credentials->set('unsplash', 'u-key');
        $this->route('https://api.unsplash.com/search/photos', ['results' => [$this->unsplashPhoto(['description' => 'Sunday vibes #soup #cosy', 'alt_description' => null])]]);

        $photo = $this->stock(false)->search('soup')[0];

        $this->assertNull($photo->title);
        $this->assertSame('Sunday vibes #soup #cosy', $photo->description, 'With no alt text, the caption is the description.');
    }

    public function test_pexels_results_take_the_alt_text_and_a_title_from_the_address(): void
    {
        $this->credentials->set('pexels', 'p-key');
        $this->route('https://api.pexels.com/v1/search', ['photos' => [[
            'id' => 2014422,
            'width' => 3000,
            'height' => 2000,
            'url' => 'https://www.pexels.com/photo/brown-rocks-during-golden-hour-2014422/',
            'photographer' => 'Joe Bloggs',
            'alt' => 'Brown rocks during golden hour',
            'src' => ['medium' => 'https://images.pexels.com/photos/2014422/a.jpeg?h=350', 'large2x' => 'https://images.pexels.com/photos/2014422/a.jpeg?w=1880', 'original' => 'https://images.pexels.com/photos/2014422/a.jpeg'],
        ]]]);

        $photo = $this->stock(false)->search('rocks', 'portrait')[0];

        $this->assertSame('pexels', $photo->source);
        $this->assertSame('2014422', $photo->id);
        $this->assertSame('Joe Bloggs on Pexels', $photo->credit);
        $this->assertSame('Pexels licence', $photo->licence);
        $this->assertSame('Brown rocks during golden hour', $photo->title);
        $this->assertSame('Brown rocks during golden hour', $photo->description);
        $this->assertSame('https://images.pexels.com/photos/2014422/a.jpeg?w=1880', $photo->url);
        $this->assertSame('p-key', $this->http->requests[0]->getHeaderLine('Authorization'));
        $this->assertStringContainsString('orientation=portrait', $this->requested()[0]);
    }

    public function test_pixabay_results_keep_their_tags(): void
    {
        $this->credentials->set('pixabay', 'x-key');
        $this->route('https://pixabay.com/api/', ['hits' => [[
            'id' => 736885,
            'pageURL' => 'https://pixabay.com/photos/tree-sunset-736885/',
            'tags' => 'tree, sunset , clouds,,',
            'webformatURL' => 'https://pixabay.com/get/g1_640.jpg',
            'largeImageURL' => 'https://pixabay.com/get/g1_1280.jpg',
            'imageWidth' => 4000,
            'imageHeight' => 2250,
            'user' => 'Bess',
        ]]]);

        $photo = $this->stock(false)->search('tree')[0];

        $this->assertSame('pixabay', $photo->source);
        $this->assertSame(['tree', 'sunset', 'clouds'], $photo->tags);
        $this->assertNull($photo->title);
        $this->assertNull($photo->description);
        $this->assertSame('Bess on Pixabay', $photo->credit);
        $this->assertSame('Pixabay licence', $photo->licence);
        $this->assertSame([4000, 2250], [$photo->width, $photo->height]);
        $this->assertSame('Tree, sunset, clouds', $photo->alt());
        $this->assertStringContainsString('orientation=horizontal', $this->requested()[0]);
        $this->assertStringContainsString('safesearch=true', $this->requested()[0]);
    }

    public function test_openverse_keeps_only_free_photos_whose_own_thumbnails_load(): void
    {
        $this->route('https://api.openverse.org/v1/images/?', ['results' => [
            $this->openversePhoto(['title' => 'IMG_2034.JPG']),
            $this->openversePhoto(['id' => 'bbb', 'title' => 'File:Old stone bridge.jpg', 'thumbnail' => 'https://api.openverse.org/v1/images/bbb/thumb/', 'license' => 'pdm']),
            $this->openversePhoto(['id' => 'broken', 'thumbnail' => 'https://api.openverse.org/v1/images/broken/thumb/']),
            $this->openversePhoto(['id' => 'insecure', 'thumbnail' => 'http://api.openverse.org/v1/images/insecure/thumb/']),
            $this->openversePhoto(['id' => 'by', 'license' => 'by', 'thumbnail' => 'https://api.openverse.org/v1/images/by/thumb/']),
        ]]);
        $this->route('https://api.openverse.org/v1/images/'.self::OPENVERSE_ID.'/thumb/', $this->jpegResponse());
        $this->route('https://api.openverse.org/v1/images/bbb/thumb/', $this->jpegResponse());
        $this->route('https://api.openverse.org/v1/images/broken/thumb/', $this->http->response(502));

        $stock = $this->stock();
        $photos = $stock->search('stone bridge', Shape::Landscape);

        $this->assertSame([self::OPENVERSE_ID, 'bbb'], array_map(fn (Photo $photo) => $photo->id, $photos));
        $this->assertNull($photos[0]->title, 'A camera file name is not a title.');
        $this->assertSame(['bridge', 'river', 'stone'], $photos[0]->tags, 'Tags people gave come before machine-made ones.');
        $this->assertSame('CC0', $photos[0]->licence);
        $this->assertSame('Kim via Openverse', $photos[0]->credit);
        $this->assertSame('https://www.flickr.com/photos/kim/1', $photos[0]->creditUrl);
        $this->assertSame('Old stone bridge', $photos[1]->title);
        $this->assertSame('Public domain', $photos[1]->licence);

        parse_str((string) parse_url($this->requested()[0], PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'stone bridge', 'page_size' => '18', 'license' => 'cc0,pdm', 'extension' => 'jpg,png', 'aspect_ratio' => 'wide', 'mature' => 'false'], $query);

        // Only Openverse's thumbnails were fetched: never an original.
        $this->assertSame(0, count(array_filter($this->requested(), fn (string $url) => str_contains($url, 'staticflickr'))));
        $this->assertNotContains('http://api.openverse.org/v1/images/insecure/thumb/', $this->requested());

        // The thumbnails checked are remembered for the judging that follows.
        $before = count($this->http->requests);
        $thumbs = $stock->thumbnails($photos);
        $this->assertCount($before, $this->http->requests);
        $this->assertNotNull($thumbs[0]);
    }

    public function test_one_library_failing_does_not_hide_the_others_and_no_key_is_logged(): void
    {
        $this->credentials->set('pixabay', 'secret-pixabay-key')->set('unsplash', 'u-key');
        $this->route('https://pixabay.com/api/', fn (RequestInterface $request) => throw NetworkError::connectFailed('pixabay.com/api/?key=secret-pixabay-key'));
        $this->route('https://api.unsplash.com/search/photos', ['results' => [$this->unsplashPhoto()]]);

        $photos = $this->stock(false)->search('soup');

        $this->assertCount(1, $photos);
        $this->assertCount(1, $this->logs);
        $this->assertSame('warning', $this->logs[0]['level']);
        $this->assertStringContainsString('pixabay', $this->logs[0]['message']);
        $this->assertStringNotContainsString('secret-pixabay-key', $this->logs[0]['message']);
    }

    public function test_a_rejected_key_is_reported_in_words(): void
    {
        $this->credentials->set('unsplash', 'u-key');
        $this->route('https://api.unsplash.com/search/photos', $this->json(['errors' => ['OAuth error']], 401));

        $this->assertSame([], $this->stock(false)->search('soup'));
        $this->assertStringContainsString('Unsplash refused the key', $this->logs[0]['message']);
    }

    public function test_a_long_search_that_finds_nothing_is_tried_again_with_its_first_two_words(): void
    {
        $this->credentials->set('unsplash', 'u-key');
        $this->route('https://api.unsplash.com/search/photos?query=old%20stone%20bridge', ['results' => []]);
        $this->route('https://api.unsplash.com/search/photos?query=old%20stone&', ['results' => [$this->unsplashPhoto()]]);

        $photos = $this->stock(false)->search('old stone bridge');

        $this->assertCount(1, $photos);
        $this->assertSame('old stone bridge', $photos[0]->term, 'The term is the search as asked.');
        $this->assertCount(2, $this->http->requests);
    }

    public function test_fetch_looks_the_photo_up_again_reports_the_download_and_checks_the_file(): void
    {
        $this->credentials->set('unsplash', 'u-key');
        $this->route('https://api.unsplash.com/photos/Ab1-x_Y', $this->unsplashPhoto());
        $this->route('https://api.unsplash.com/photos/Ab1-x_Y/download', ['url' => 'x']);
        $this->route('https://images.unsplash.com/photo-1?ixid=x&w=2400', $this->jpegResponse(120, 80));

        $file = $this->stock(false)->fetch('unsplash', 'Ab1-x_Y');

        $this->assertSame('jpg', $file->extension);
        $this->assertSame('image/jpeg', $file->mime);
        $this->assertSame([120, 80], array_slice((array) getimagesizefromstring($file->content), 0, 2));
        $this->assertSame('a bowl of soup on a wooden table', $file->photo->description);
        $this->assertSame('Ann Lee on Unsplash', $file->photo->credit);

        $this->assertSame([
            'https://api.unsplash.com/photos/Ab1-x_Y',
            'https://api.unsplash.com/photos/Ab1-x_Y/download?ixid=x',
            'https://images.unsplash.com/photo-1?ixid=x&w=2400&fm=jpg&q=82',
        ], $this->requested());
        $this->assertSame('Client-ID u-key', $this->http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('', $this->http->requests[2]->getHeaderLine('Authorization'), 'The key is not sent to the file host.');
        $this->assertContains(true, $this->http->streams, 'The file is streamed.');
    }

    public function test_fetch_refuses_unknown_photos_and_libraries(): void
    {
        $this->credentials->set('unsplash', 'u-key');

        foreach ([['unsplash', '../etc'], ['unsplash', ''], ['pexels', '123'], ['flickr', '1'], ['unsplash', str_repeat('a', 65)]] as [$source, $id]) {
            try {
                $this->stock(false)->fetch($source, $id);
                $this->fail("Expected {$source}/{$id} to be refused.");
            } catch (PhotoUnavailable $exception) {
                $this->assertSame('That photograph could not be found.', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function test_fetch_refuses_an_openverse_photo_that_is_not_free_or_not_https(): void
    {
        $this->route('https://api.openverse.org/v1/images/'.self::OPENVERSE_ID.'/', $this->openversePhoto(['license' => 'by-sa']));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'not free of conditions');

        $this->route('https://api.openverse.org/v1/images/'.self::OPENVERSE_ID.'/', $this->openversePhoto(['url' => 'http://farm1.example.org/1.jpg']));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'no secure download address');
        $this->assertNotContains('http://farm1.example.org/1.jpg', $this->requested());
    }

    public function test_redirects_are_followed_over_https_only(): void
    {
        $this->route('https://api.openverse.org/v1/images/'.self::OPENVERSE_ID.'/', $this->openversePhoto());
        $this->route('https://live.staticflickr.com/1.jpg', $this->http->response(302, '', ['Location' => '/moved/1.jpg']));
        $this->route('https://live.staticflickr.com/moved/1.jpg', $this->http->response(301, '', ['Location' => 'https://cdn.example.org/1.jpg']));
        $this->route('https://cdn.example.org/1.jpg', $this->jpegResponse());

        $this->assertSame('jpg', $this->stock()->fetch('openverse', self::OPENVERSE_ID)->extension);

        $this->route('https://cdn.example.org/1.jpg', $this->http->response(307, '', ['Location' => 'http://cdn.example.org/1.jpg']));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'no secure download address');
        $this->assertNotContains('http://cdn.example.org/1.jpg', $this->requested());

        $this->route('https://cdn.example.org/1.jpg', $this->http->response(302, '', ['Location' => 'https://127.0.0.1/1.jpg']));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'no secure download address');

        $this->route('https://cdn.example.org/1.jpg', $this->http->response(302, '', ['Location' => 'https://cdn.example.org/1.jpg']));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'moved too many times');

        $this->route('https://cdn.example.org/1.jpg', $this->http->response(302));
        $this->assertRefused(fn () => $this->stock()->fetch('openverse', self::OPENVERSE_ID), 'without saying where');
    }

    public function test_a_redirect_to_another_host_does_not_carry_the_key(): void
    {
        $this->credentials->set('pexels', 'p-key');
        $this->route('https://api.pexels.com/v1/photos/7', $this->http->response(302, '', ['Location' => 'https://api.pexels.com/v2/photos/7']));
        $this->route('https://api.pexels.com/v2/photos/7', $this->http->response(302, '', ['Location' => 'https://elsewhere.example.com/photos/7']));
        $this->route('https://elsewhere.example.com/photos/7', ['id' => 7, 'src' => ['medium' => 'https://images.pexels.com/7-m.jpg', 'large2x' => 'https://images.pexels.com/7.jpg']]);
        $this->route('https://images.pexels.com/7.jpg', $this->jpegResponse());

        $this->stock(false)->fetch('pexels', '7');

        $this->assertSame('p-key', $this->http->requests[1]->getHeaderLine('Authorization'), 'Same host keeps the key.');
        $this->assertSame('', $this->http->requests[2]->getHeaderLine('Authorization'), 'Another host does not get it.');
    }

    public function test_files_that_are_too_large_or_not_images_are_refused(): void
    {
        $this->credentials->set('pixabay', 'x-key');
        $this->route('https://pixabay.com/api/', ['hits' => [['id' => 5, 'webformatURL' => 'https://pixabay.com/get/5_640.jpg', 'largeImageURL' => 'https://pixabay.com/get/5_1280.jpg']]]);

        $this->route('https://pixabay.com/get/5_1280.jpg', $this->http->response(200, self::jpeg(), ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (16 * 1024 * 1024)]));
        $this->assertRefused(fn () => $this->stock(false)->fetch('pixabay', '5'), 'too large');

        $this->route('https://pixabay.com/get/5_1280.jpg', $this->http->response(200, '<html>Sign in</html>', ['Content-Type' => 'text/html']));
        $this->assertRefused(fn () => $this->stock(false)->fetch('pixabay', '5'), 'not an image');

        $this->route('https://pixabay.com/get/5_1280.jpg', $this->http->response(200, '<?php echo 1;', ['Content-Type' => 'image/jpeg']));
        $this->assertRefused(fn () => $this->stock(false)->fetch('pixabay', '5'), 'not an image');

        $this->route('https://pixabay.com/get/5_1280.jpg', $this->http->response(200, (string) base64_decode('R0lGODlhAQABAAAAACw='), ['Content-Type' => 'image/gif']));
        $this->assertRefused(fn () => $this->stock(false)->fetch('pixabay', '5'), 'not an image');

        $this->route('https://pixabay.com/get/5_1280.jpg', $this->http->response(404));
        $this->assertRefused(fn () => $this->stock(false)->fetch('pixabay', '5'), 'could not be downloaded (404)');
    }

    public function test_a_thumbnail_is_read_no_further_than_its_cap(): void
    {
        $photo = self::photo('big');
        $this->route('https://images.example.com/big.jpg', $this->http->response(200, self::jpeg().str_repeat('x', 2 * 1024 * 1024), ['Content-Type' => 'image/jpeg']));
        $this->route('https://images.example.com/ok.jpg', $this->jpegResponse());

        $thumbs = $this->stock()->thumbnails(['a' => $photo, 'b' => self::photo('ok'), 'c' => self::photo('missing')]);

        $this->assertSame(['a', 'b', 'c'], array_keys($thumbs));
        $this->assertNull($thumbs['a']);
        $this->assertNotNull($thumbs['b']);
        $this->assertNull($thumbs['c']);
    }

    public function test_only_public_https_addresses_are_fetched(): void
    {
        $downloader = new Downloader($this->http);

        foreach (['https://images.unsplash.com/a.jpg', 'https://[2606:4700::1111]/a.jpg', 'https://8.8.8.8/a.jpg'] as $url) {
            $this->assertTrue($downloader->secure($url), $url);
        }

        foreach (['http://images.unsplash.com/a.jpg', 'ftp://example.com/a.jpg', 'https://localhost/a.jpg', 'https://foo.localhost/a', 'https://printer.local/a', 'https://db.internal/a', 'https://10.0.0.5/a.jpg', 'https://192.168.1.1/a', 'https://169.254.169.254/latest', 'https://[::1]/a', 'https://user:pass@example.com/a', 'https:///a', 'not a url'] as $url) {
            $this->assertFalse($downloader->secure($url), $url);
        }
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected the photo to be refused ({$message}).");
        } catch (PhotoUnavailable $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unsplashPhoto(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'Ab1-x_Y',
            'width' => 4000,
            'height' => 3000,
            'description' => "Grandma's kitchen",
            'alt_description' => 'a bowl of soup on a wooden table',
            'urls' => ['small' => 'https://images.unsplash.com/photo-1?w=400', 'regular' => 'https://images.unsplash.com/photo-1?w=1080', 'raw' => 'https://images.unsplash.com/photo-1?ixid=x'],
            'links' => ['html' => 'https://unsplash.com/photos/Ab1-x_Y', 'download_location' => 'https://api.unsplash.com/photos/Ab1-x_Y/download?ixid=x'],
            'user' => ['name' => 'Ann Lee'],
            'tags' => [['title' => 'soup'], ['title' => 'kitchen']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openversePhoto(array $overrides = []): array
    {
        return $overrides + [
            'id' => self::OPENVERSE_ID,
            'title' => 'A bridge',
            'creator' => 'Kim',
            'license' => 'cc0',
            'foreign_landing_url' => 'https://www.flickr.com/photos/kim/1',
            'url' => 'https://live.staticflickr.com/1.jpg',
            'thumbnail' => 'https://api.openverse.org/v1/images/'.self::OPENVERSE_ID.'/thumb/',
            'width' => 1024,
            'height' => 768,
            'tags' => [['name' => 'stone', 'accuracy' => 0.93], ['name' => 'bridge', 'accuracy' => null], ['name' => 'river']],
        ];
    }
}
