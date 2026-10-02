<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The same guards on a real Guzzle client: it must hand redirects back for
 * core to check, not follow them itself, and thumbnails go side by side.
 */
class GuzzleDownloadTest extends TestCase
{
    /** @var array<int, string> */
    private array $sent = [];

    public function test_guzzle_hands_redirects_back_so_an_http_hop_is_refused(): void
    {
        $stock = $this->stock([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => 'abc', 'license' => 'cc0', 'url' => 'https://cdn.example.org/1.jpg', 'thumbnail' => 'https://api.openverse.org/t/abc'])),
            new Response(302, ['Location' => 'http://cdn.example.org/1.jpg']),
            new Response(200, ['Content-Type' => 'image/jpeg'], 'should never be read'),
        ]);

        try {
            $stock->fetch('openverse', 'abc');
            $this->fail('Expected the http hop to be refused.');
        } catch (PhotoUnavailable $exception) {
            $this->assertStringContainsString('no secure download address', $exception->getMessage());
        }

        $this->assertSame(['https://api.openverse.org/v1/images/abc/', 'https://cdn.example.org/1.jpg'], $this->sent);
    }

    public function test_guzzle_fetches_thumbnails_side_by_side_and_follows_https_redirects(): void
    {
        $stock = $this->stock([
            new Response(200, ['Content-Type' => 'image/jpeg'], ImagesTestCase::jpegBytes()),
            new Response(301, ['Location' => 'https://cdn.example.org/b2.jpg']),
            new Response(200, ['Content-Type' => 'image/jpeg'], ImagesTestCase::jpegBytes()),
        ]);

        $thumbs = $stock->thumbnails([ImagesTestCase::photoFor('a'), ImagesTestCase::photoFor('b')]);

        $this->assertNotNull($thumbs[0]);
        $this->assertNotNull($thumbs[1]);
        $this->assertSame(['https://images.example.com/a.jpg', 'https://images.example.com/b.jpg', 'https://cdn.example.org/b2.jpg'], $this->sent);
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function stock(array $responses): StockSearch
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->sent[] = (string) $request->getUri();

                return $handler($request, $options);
            };
        });

        return new StockSearch(new GuzzleHttpClients(['handler' => $stack]), new ArrayCredentials);
    }
}
