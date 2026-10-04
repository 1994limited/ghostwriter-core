<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\HttpLinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkProbeContractTest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Core's PSR-18 probe passes the contract an addon's own would. */
final class HttpLinkProbeContractTest extends LinkProbeContractTest
{
    /** @var list<RequestInterface> */
    public static array $sent = [];

    protected function linkProbe(array $answers): LinkProbe
    {
        self::$sent = [];

        return new HttpLinkProbe(new class($answers) implements ClientInterface, HttpClients
        {
            /**
             * @param  array<string, int|string|array<string, int>>  $answers
             */
            public function __construct(private readonly array $answers) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                HttpLinkProbeContractTest::$sent[] = $request;
                $answer = $this->answers[(string) $request->getUri()] ?? 404;

                if ($answer === 'dns') {
                    throw (new NetworkError('cURL error 6: Could not resolve host: gone.example'))->withRequest($request);
                }

                if ($answer === 'timeout') {
                    throw NetworkError::timedOut(10)->withRequest($request);
                }

                return new Response(is_array($answer) ? ($answer[$request->getMethod()] ?? 404) : (int) $answer);
            }

            public function client(int $timeout): ClientInterface
            {
                return $this;
            }

            public function requestFactory(): RequestFactoryInterface
            {
                return new HttpFactory;
            }

            public function streamFactory(): StreamFactoryInterface
            {
                return new HttpFactory;
            }
        });
    }

    public function test_it_says_what_it_is_and_asks_for_one_byte_with_get(): void
    {
        $this->linkProbe(['https://example.org/page' => ['HEAD' => 405, 'GET' => 206]])->probe('https://example.org/page', 10);

        $this->assertSame(['HEAD', 'GET'], array_map(fn (RequestInterface $r) => $r->getMethod(), self::$sent));
        $this->assertStringStartsWith('Ghostwriter link check', self::$sent[0]->getHeaderLine('User-Agent'));
        $this->assertSame('bytes=0-0', self::$sent[1]->getHeaderLine('Range'));
    }
}
