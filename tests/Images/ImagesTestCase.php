<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

/**
 * Photo search over a mocked network. Requests are answered by address:
 * route() a prefix to a response, and the longest matching prefix answers.
 * Anything unrouted gets a 404.
 */
abstract class ImagesTestCase extends TestCase
{
    protected MockHttpClient $http;

    protected ArrayCredentials $credentials;

    /** @var array<string, Closure(RequestInterface): ResponseInterface> */
    protected array $routes = [];

    /** @var array<int, array{level: string, message: string}> */
    protected array $logs = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient;
        $this->credentials = new ArrayCredentials;
        $this->routes = [];
        $this->logs = [];
        $this->http->queue(...array_fill(0, 400, fn (RequestInterface $request) => $this->dispatch($request)));
    }

    /**
     * @param  ResponseInterface|Closure(RequestInterface): ResponseInterface|array<mixed>  $answer  A response, a closure, or an array to send as JSON.
     */
    protected function route(string $prefix, ResponseInterface|Closure|array $answer): void
    {
        $answer = is_array($answer) ? $this->json($answer) : $answer;

        if ($answer instanceof ResponseInterface) {
            // Bodies are read and closed, so each request gets a fresh copy.
            $body = (string) $answer->getBody();
            $answer = fn () => $answer->withBody($this->http->streamFactory()->createStream($body));
        }

        $this->routes[$prefix] = $answer;
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function json(array $body, int $status = 200): ResponseInterface
    {
        return $this->http->response($status, (string) json_encode($body), ['Content-Type' => 'application/json']);
    }

    protected function jpegResponse(int $width = 40, int $height = 30): ResponseInterface
    {
        return $this->http->response(200, self::jpeg($width, $height), ['Content-Type' => 'image/jpeg']);
    }

    protected function stock(bool $openverse = true): StockSearch
    {
        return new StockSearch($this->http, $this->credentials, $openverse, $this->logger());
    }

    protected function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger
        {
            /** @param array<int, array{level: string, message: string}> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }

    /**
     * @return array<int, string> Every address requested, in order.
     */
    protected function requested(): array
    {
        return array_map(fn (RequestInterface $request) => (string) $request->getUri(), $this->http->requests);
    }

    protected static function jpeg(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** For tests outside this case. */
    public static function jpegBytes(): string
    {
        return self::jpeg();
    }

    /** For tests outside this case. */
    public static function photoFor(string $id): Photo
    {
        return self::photo($id);
    }

    /**
     * A Pexels photo by default: a library whose photos a model may judge.
     */
    protected static function photo(string $id, string $term = 'pottery', string $source = 'pexels', ?string $description = null): Photo
    {
        return new Photo($source, $id, "https://images.example.com/{$id}.jpg", 'Ann on Pexels', "https://www.pexels.com/photo/{$id}/", 'Pexels licence', description: $description, term: $term);
    }

    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $routes = $this->routes;
        uksort($routes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($routes as $prefix => $answer) {
            if (str_starts_with($url, $prefix)) {
                return $answer($request);
            }
        }

        return $this->http->response(404, 'Not found');
    }
}
