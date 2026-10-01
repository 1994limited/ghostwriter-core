<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Http;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Unreachable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Multipart;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;

/**
 * Retries (CRA-4) and error mapping, which every provider shares.
 */
class TransportTest extends ProviderTestCase
{
    private const URL = 'https://api.example.com/v1/test';

    /**
     * @return array<string, mixed>
     */
    private function send(?RetryPolicy $retry = null, int $timeout = 300): array
    {
        return $this->transport('anthropic', $retry)->json(self::URL, ['x-api-key' => 'sk-secret-key'], ['q' => 1], $timeout, 'writer');
    }

    public function test_a_rate_limited_call_waits_as_asked_then_succeeds(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Rate limited']], 429, ['retry-after' => '7']);
        $this->http->queueJson(['ok' => true]);

        $this->assertSame(['ok' => true], $this->send());
        $this->assertSame([7.0], $this->sleeper->waits);
        $this->assertCount(2, $this->http->requests);

        $retry = $this->logged('warning')[0];
        $this->assertSame(['agent' => 'writer', 'provider' => 'anthropic', 'status' => 429, 'attempt' => 1, 'wait' => 7.0], $retry['context']);
    }

    public function test_an_overloaded_provider_is_tried_three_times_with_growing_jittered_waits(): void
    {
        foreach (range(1, 3) as $i) {
            $this->http->queueJson(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);
        }

        try {
            $this->send();
            $this->fail('Expected Overloaded.');
        } catch (Overloaded $exception) {
            $this->assertSame('Anthropic is busy right now. Try again shortly.', $exception->getMessage());
            $this->assertTrue($exception->retryable());
        }

        $this->assertCount(3, $this->http->requests);
        // Full jitter, here pinned at its top: random(0, min(30, 2^attempt)).
        $this->assertSame([2.0, 4.0], $this->sleeper->waits);
    }

    public function test_the_jitter_is_random_between_zero_and_the_backoff(): void
    {
        $asked = [];
        $retry = new RetryPolicy(random: function (float $max) use (&$asked) {
            $asked[] = $max;

            return $max / 2;
        });

        $this->http->queue($this->http->response(503), $this->http->response(502), $this->http->response(200, '{}'));

        $this->send($retry);

        $this->assertSame([2.0, 4.0], $asked);
        $this->assertSame([1.0, 2.0], $this->sleeper->waits);

        $default = new RetryPolicy;
        foreach (range(1, 20) as $attempt) {
            $wait = $default->wait($attempt);
            $this->assertNotNull($wait);
            $this->assertGreaterThanOrEqual(0, $wait);
            $this->assertLessThanOrEqual(min(30, 2 ** $attempt), $wait);
        }
    }

    public function test_retry_after_can_be_an_http_date_or_milliseconds(): void
    {
        $now = 1_800_000_000.0;
        $retry = new RetryPolicy(random: fn (float $max) => $max, clock: fn () => $now);

        $this->http->queue(
            $this->http->response(429, '{}', ['retry-after' => gmdate('D, d M Y H:i:s', (int) $now + 12).' GMT']),
            $this->http->response(429, '{}', ['retry-after-ms' => '1500', 'retry-after' => '9']),
            $this->http->response(200, '{}'),
        );

        $this->send($retry);

        $this->assertSame([12.0, 1.5], $this->sleeper->waits);
    }

    public function test_a_wait_longer_than_the_cap_is_not_waited_for(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Slow down']], 429, ['retry-after' => '120']);

        try {
            $this->send();
            $this->fail('Expected RateLimited.');
        } catch (RateLimited $exception) {
            $this->assertSame('Anthropic is limiting requests and asked Ghostwriter to wait 120 seconds. Try again then.', $exception->getMessage());
            $this->assertSame(429, $exception->status());
        }

        $this->assertSame([], $this->sleeper->waits);
        $this->assertCount(1, $this->http->requests);
    }

    public function test_rate_limited_after_every_try(): void
    {
        foreach (range(1, 3) as $i) {
            $this->http->queueJson([], 429);
        }

        $this->expectException(RateLimited::class);
        $this->expectExceptionMessage('Anthropic is limiting requests. Try again in a minute.');

        $this->send();
    }

    public function test_a_request_that_is_simply_wrong_is_not_tried_again(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Bad request']], 400);
        $this->http->queueJson(['error' => ['message' => 'Invalid key']], 401);
        $this->http->queueJson(['error' => ['message' => 'Not allowed']], 403);
        $this->http->queueJson(['error' => ['message' => 'Too big']], 413);

        foreach ([BadResponse::class, AuthenticationFailed::class, AuthenticationFailed::class, BadResponse::class] as $expected) {
            try {
                $this->send();
                $this->fail("Expected {$expected}.");
            } catch (BadResponse|AuthenticationFailed $exception) {
                $this->assertInstanceOf($expected, $exception);
                $this->assertFalse($exception->retryable());
            }
        }

        $this->assertCount(4, $this->http->requests);
        $this->assertSame([], $this->sleeper->waits);
    }

    public function test_a_connection_failure_is_retried(): void
    {
        $this->http->queue(NetworkError::connectFailed(), NetworkError::connectFailed(), $this->http->response(200, '{"ok":1}'));

        $this->assertSame(['ok' => 1], $this->send());
        $this->assertCount(2, $this->sleeper->waits);
    }

    public function test_a_connection_that_never_works_is_unreachable(): void
    {
        $this->http->queue(NetworkError::connectFailed(), NetworkError::connectFailed(), NetworkError::connectFailed());

        $this->expectException(Unreachable::class);
        $this->expectExceptionMessage('Could not reach Anthropic: cURL error 7');

        $this->send();
    }

    public function test_a_response_timeout_is_not_retried(): void
    {
        $this->http->queue(NetworkError::timedOut(180), $this->http->response(200, '{}'));

        try {
            $this->send(timeout: 180);
            $this->fail('Expected Unreachable.');
        } catch (Unreachable $exception) {
            $this->assertSame('Anthropic took longer than 180 seconds.', $exception->getMessage());
        }

        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->sleeper->waits);
        $this->assertSame([180], $this->http->timeouts);
    }

    public function test_retries_stop_when_the_time_budget_is_spent(): void
    {
        // Waits of 2 and then 4 seconds would not fit a 1-second timeout × 3 attempts.
        $this->http->queue($this->http->response(503), $this->http->response(503), $this->http->response(200, '{}'));

        $this->expectException(Overloaded::class);

        try {
            $this->send(timeout: 1);
        } finally {
            $this->assertSame([2.0], $this->sleeper->waits);
        }
    }

    public function test_a_server_error_that_outlasts_the_retries_says_what_the_provider_said(): void
    {
        foreach (range(1, 3) as $i) {
            $this->http->queueJson(['error' => ['message' => 'Internal error']], 500);
        }

        try {
            $this->send();
            $this->fail('Expected BadResponse.');
        } catch (BadResponse $exception) {
            $this->assertSame('Anthropic said no (500): Internal error', $exception->getMessage());
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_a_key_never_appears_in_a_message(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Key sk-secret-key is not valid for this model']], 400);
        $this->http->queue(new NetworkError('Failed to connect with header x-api-key: sk-secret-key'), NetworkError::connectFailed(), NetworkError::connectFailed());

        foreach (range(1, 2) as $i) {
            try {
                $this->send();
                $this->fail('Expected an exception.');
            } catch (BadResponse|Unreachable $exception) {
                $this->assertStringNotContainsString('sk-secret-key', $exception->getMessage());
                $this->assertStringContainsString('[redacted]', $exception->getMessage().$this->logged('error')[0]['context']['message']);
            }
        }

        $this->assertStringNotContainsString('sk-secret-key', (string) json_encode($this->logs));
    }

    public function test_a_body_that_is_not_json_is_a_bad_response(): void
    {
        $this->http->queue($this->http->response(200, '<html>'));

        $this->expectException(BadResponse::class);
        $this->expectExceptionMessage('Anthropic sent back something that was not JSON.');

        $this->send();
    }

    public function test_a_multipart_body_is_sent_whole_on_every_try(): void
    {
        $this->http->queue($this->http->response(503), $this->http->response(200, '{}'));

        $form = (new Multipart('BOUNDARY'))->add('prompt', 'A "lighthouse"')->add('image[]', 'BYTES', 'a.png', 'image/png');
        $this->transport('openai')->multipart(self::URL, [], $form, 300);

        $expected = "--BOUNDARY\r\nContent-Disposition: form-data; name=\"prompt\"\r\n\r\nA \"lighthouse\"\r\n"
            ."--BOUNDARY\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\nBYTES\r\n"
            ."--BOUNDARY--\r\n";

        foreach ($this->http->requests as $request) {
            $this->assertSame($expected, (string) $request->getBody());
            $this->assertSame('multipart/form-data; boundary=BOUNDARY', $request->getHeaderLine('content-type'));
        }
    }
}
