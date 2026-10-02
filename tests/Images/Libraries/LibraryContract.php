<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use Psr\Http\Message\RequestInterface;

/**
 * What every PhotoLibrary adapter must do, over the mocked network of
 * ImagesTestCase. An adapter's test extends ImagesTestCase, uses this
 * trait and routes the library's answers:
 *
 *     final class UnsplashTest extends ImagesTestCase
 *     {
 *         use LibraryContract;
 *
 *         protected function library(): PhotoLibrary { return new Unsplash($this->http, $this->credentials); }
 *         protected function routeSearch(): void { $this->route('https://api.unsplash.com/search/photos', [...]); }
 *         ...
 *     }
 */
trait LibraryContract
{
    /** The adapter, with its keys set (contractKey()). */
    abstract protected function library(): PhotoLibrary;

    /** Route a search answer with at least one photo. */
    abstract protected function routeSearch(): void;

    /** Route the library's answer about the photo with this ID. */
    abstract protected function routePhoto(string $id): void;

    /** An ID the library would give. */
    abstract protected function contractId(): string;

    /** The key or token the adapter sends, which must never show in a message; '' for none. */
    abstract protected function contractKey(): string;

    public function test_search_maps_ids_thumbs_and_offers(): void
    {
        $this->routeSearch();
        $library = $this->library();

        $photos = $library->search(new SearchQuery('mended pottery', perPage: 3));

        $this->assertNotSame([], $photos);
        $this->assertLessThanOrEqual(3, count($photos));

        foreach ($photos as $photo) {
            $this->assertSame($library->id(), $photo->source);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $photo->id);
            $this->assertStringStartsWith('https://', $photo->thumb);
            $this->assertNotSame('', $photo->credit);
            $this->assertSame('mended pottery', $photo->term);
            $this->assertSame($library->capabilities()->free, $photo->isFree(), 'A paid library\'s photos carry a paid offer.');
            $this->assertFalse($photo->picked);
        }
    }

    public function test_photo_refuses_ids_that_are_not_the_librarys(): void
    {
        $library = $this->library();

        foreach (['', '../../etc/passwd', 'a b', "a\nb", 'https://example.com/a.jpg', str_repeat('a', 65)] as $id) {
            try {
                $library->photo($id);
                $this->fail("Expected \"{$id}\" to be refused.");
            } catch (PhotoUnavailable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->http->requests, 'Nothing is asked of the library for an ID that can\'t be its.');
    }

    public function test_a_photo_is_looked_up_again_by_id(): void
    {
        $this->routePhoto($this->contractId());

        $photo = $this->library()->photo($this->contractId());

        $this->assertSame($this->contractId(), $photo->id);
        $this->assertSame($this->library()->id(), $photo->source);
    }

    public function test_errors_never_hold_a_key(): void
    {
        $this->route('https://', fn (RequestInterface $request) => $this->http->response(500, 'Failed for key '.$this->contractKey()));
        $library = $this->library();

        foreach ([fn () => $library->search(new SearchQuery('pottery')), fn () => $library->photo($this->contractId()), fn () => $library->fetch($this->contractId())] as $call) {
            try {
                $call();
                $this->fail('Expected the library to fail.');
            } catch (PhotoUnavailable $exception) {
                $this->assertNotSame('', $exception->getMessage());

                if ($this->contractKey() !== '') {
                    $this->assertStringNotContainsString($this->contractKey(), $exception->getMessage());
                }
            }
        }
    }

    public function test_its_capabilities_hang_together(): void
    {
        $library = $this->library();
        $capabilities = $library->capabilities();

        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', $library->id());
        $this->assertNotSame('', $library->label());
        $this->assertNotNull($capabilities->termsCheckedAt, 'Every adapter records when its terms were last read.');

        if ($capabilities->free) {
            $this->assertNull($capabilities->previewKeepDays);
            $this->assertNull($capabilities->previewStorage);
        } else {
            $this->assertGreaterThan(0, $capabilities->previewKeepDays);
            $this->assertNotNull($capabilities->previewStorage);
            $this->assertFalse($capabilities->mayRank, 'A paid library is never ranked unless counsel has cleared it; change this test when one is.');
        }
    }
}
