<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Downloader::post(), for token and licence calls: sent once, never
 * retried or redirected, and never saying a key.
 */
final class DownloaderPostTest extends ImagesTestCase
{
    private const KEY = 'secret-api-key';

    private const URL = 'https://api.stock.example/v3/downloads/images/123?product_type=easyaccess&key='.self::KEY;

    public function test_it_sends_json_or_a_form_once_and_reads_the_answer(): void
    {
        $this->route('https://api.stock.example/', ['uri' => 'https://delivery.stock.example/123.jpg']);

        $answer = $this->downloader()->post(self::URL, ['note' => 'record-1'], ['Api-Key' => self::KEY], purchase: true);
        $token = $this->downloader()->post('https://api.stock.example/oauth2/token', ['grant_type' => 'client_credentials', 'client_id' => 'id'], form: true);

        $this->assertSame(['uri' => 'https://delivery.stock.example/123.jpg'], $answer);
        $this->assertSame(['uri' => 'https://delivery.stock.example/123.jpg'], $token);
        $this->assertCount(2, $this->http->requests);

        [$licence, $tokenRequest] = $this->http->requests;
        $this->assertSame('POST', $licence->getMethod());
        $this->assertSame('application/json', $licence->getHeaderLine('Content-Type'));
        $this->assertSame('{"note":"record-1"}', (string) $licence->getBody());
        $this->assertSame(self::KEY, $licence->getHeaderLine('Api-Key'));
        $this->assertSame('application/x-www-form-urlencoded', $tokenRequest->getHeaderLine('Content-Type'));
        $this->assertSame('grant_type=client_credentials&client_id=id', (string) $tokenRequest->getBody());
        $this->assertSame([false, false], $this->http->streams, 'Clients that hand redirects back.');
    }

    public function test_a_purchase_is_never_retried_whatever_the_answer(): void
    {
        foreach ([
            'server error' => fn () => $this->http->response(503, 'Busy, key '.self::KEY),
            'timeout' => fn (RequestInterface $request) => throw NetworkError::timedOut(60)->withRequest($request),
            'connection' => fn (RequestInterface $request) => throw NetworkError::connectFailed('api.stock.example/?key='.self::KEY)->withRequest($request),
            'redirect' => fn () => $this->http->response(302, '', ['Location' => 'https://elsewhere.example/buy']),
            'request timeout' => fn () => $this->http->response(408),
            'unreadable' => fn () => $this->http->response(200, '<html>Thanks!</html>'),
        ] as $case => $answer) {
            $this->setUp();
            $this->route('https://api.stock.example/', $answer);
            $this->route('https://elsewhere.example/', ['id' => 'x']);

            $exception = $this->failure(fn () => $this->downloader()->post(self::URL, ['a' => 1], ['Api-Key' => self::KEY], purchase: true));

            $this->assertInstanceOf(LicensingUncertain::class, $exception, $case);
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage(), $case);
            $this->assertCount(1, $this->http->requests, "{$case}: sent once, never again, never redirected.");
        }
    }

    public function test_a_plain_refusal_says_what_and_nothing_was_bought(): void
    {
        foreach ([
            [402, InsufficientBalance::class, 'nothing left to license this with'],
            [401, NotConnected::class, 'refused the key'],
            [403, NotConnected::class, 'refused the key'],
            [409, LicenceRefused::class, "wouldn't license that photograph (409)"],
            [429, PhotoUnavailable::class, 'too many requests'],
        ] as [$status, $class, $words]) {
            $this->setUp();
            $this->route('https://api.stock.example/', fn () => $this->http->response($status, 'key '.self::KEY));

            $exception = $this->failure(fn () => $this->downloader()->post(self::URL, [], ['Api-Key' => self::KEY], label: 'Getty Images', purchase: true));

            $this->assertInstanceOf($class, $exception, (string) $status);
            $this->assertNotInstanceOf(LicensingUncertain::class, $exception);
            $this->assertStringContainsString($words, $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
            $this->assertCount(1, $this->http->requests);
        }
    }

    public function test_outside_a_purchase_failures_are_plain_and_name_the_host_at_most(): void
    {
        $this->route('https://api.stock.example/', fn (RequestInterface $request) => throw NetworkError::connectFailed('api.stock.example/?key='.self::KEY)->withRequest($request));

        $exception = $this->failure(fn () => $this->downloader()->post(self::URL));

        $this->assertSame(PhotoUnavailable::class, $exception::class);
        $this->assertSame('Could not reach api.stock.example. Try again in a moment.', $exception->getMessage());

        $this->setUp();
        $this->route('https://api.stock.example/', fn () => $this->http->response(500));
        $this->assertSame(PhotoUnavailable::class, $this->failure(fn () => $this->downloader()->post(self::URL))::class);
    }

    public function test_only_secure_public_addresses_are_posted_to(): void
    {
        foreach (['http://api.stock.example/buy', 'https://localhost/buy', 'https://10.0.0.1/buy'] as $url) {
            $this->assertInstanceOf(PhotoUnavailable::class, $this->failure(fn () => $this->downloader()->post($url, purchase: true)));
        }

        $this->assertSame([], $this->http->requests);
    }

    private function downloader(): Downloader
    {
        return new Downloader($this->http);
    }

    private function failure(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Expected it to fail.');
    }
}
