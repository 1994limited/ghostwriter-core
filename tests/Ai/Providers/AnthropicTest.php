<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AnthropicTest extends ProviderTestCase
{
    private function claude(?string $baseUrl = null, bool $fallbacks = true, ?string $model = null): Anthropic
    {
        return new Anthropic('secret', $this->transport('anthropic'), $model, $baseUrl, 300, $fallbacks);
    }

    public function test_claude_is_sent_the_conversation_images_effort_and_a_fallback(): void
    {
        $this->http->queueJson([
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '<reply>Hi.</reply>']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 50, 'output_tokens' => 40],
        ]);

        $response = $this->claude()->text($this->request(images: [$this->png()], effort: 'low'));

        $this->assertSame('<reply>Hi.</reply>', $response->text);
        $this->assertSame(StopReason::End, $response->stopReason);
        $this->assertSame([200, 40], [$response->usage->input, $response->usage->output]);
        $this->assertSame([200, 40], [$response->inputTokens, $response->outputTokens], 'The deprecated accessors still work.');
        $this->assertSame(['anthropic', 'claude-opus-5-5'], [$response->provider, $response->model]);

        $request = $this->http->requests[0];
        $body = $this->http->body(0);

        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
        $this->assertSame('application/json', $request->getHeaderLine('content-type'));
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(16000, $body['max_tokens'], "The writer's default from Agents.");
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame('Be brief.', $body['system']);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame(['image', 'text'], array_column($body['messages'][2]['content'], 'type'));
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG], $body['messages'][2]['content'][0]['source']);
        $this->assertSame([300], $this->http->timeouts);

        $this->assertSame('A model call finished.', $this->logged('info')[0]['message']);
        $this->assertSame(['agent' => 'writer', 'input_tokens' => 200, 'stop_reason' => 'end'], array_intersect_key($this->logged('info')[0]['context'], ['agent' => 1, 'input_tokens' => 1, 'stop_reason' => 1]));
    }

    public function test_the_agent_sets_the_defaults_and_the_request_can_override_them(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->claude()->text($this->request(agent: 'photo-picker'));
        $this->claude()->text($this->request(agent: 'photo-picker', effort: 'high', maxTokens: 500));

        $this->assertSame([2000, ['effort' => 'low']], [$this->http->body(0)['max_tokens'], $this->http->body(0)['output_config']]);
        $this->assertSame([500, ['effort' => 'high']], [$this->http->body(1)['max_tokens'], $this->http->body(1)['output_config']]);
    }

    public function test_every_model_with_refusal_classifiers_gets_the_fallback(): void
    {
        $models = ['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

        foreach ($models as $i => $model) {
            $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);
            $this->claude()->text($this->request(model: $model));

            $this->assertSame('default', $this->http->body($i)['fallbacks'] ?? null, $model);
        }
    }

    public function test_an_older_claude_model_gets_the_plain_request(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->claude()->text($this->request(model: 'claude-haiku-4-5', effort: 'low'));

        $body = $this->http->body(0);
        $this->assertArrayNotHasKey('fallbacks', $body);
        $this->assertArrayNotHasKey('output_config', $body);
        $this->assertFalse($this->http->requests[0]->hasHeader('anthropic-beta'));
    }

    public function test_a_model_with_effort_but_no_classifiers_gets_effort_only(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->claude()->text($this->request(model: 'claude-sonnet-4-6', effort: 'medium'));

        $this->assertSame(['effort' => 'medium'], $this->http->body(0)['output_config']);
        $this->assertArrayNotHasKey('fallbacks', $this->http->body(0));
    }

    public function test_the_fallback_is_not_sent_through_a_gateway_or_when_turned_off(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->claude(baseUrl: 'https://gateway.example.com/anthropic/')->text($this->request());
        $this->claude(fallbacks: false)->text($this->request());

        $this->assertSame('https://gateway.example.com/anthropic/v1/messages', (string) $this->http->requests[0]->getUri());

        foreach ([0, 1] as $i) {
            $this->assertArrayNotHasKey('fallbacks', $this->http->body($i));
            $this->assertFalse($this->http->requests[$i]->hasHeader('anthropic-beta'));
        }
    }

    public function test_a_base_url_must_be_https_unless_it_is_local(): void
    {
        foreach (['http://localhost:8080', 'http://127.0.0.1', 'http://[::1]:4000/v1', 'https://proxy.example.com'] as $url) {
            $this->assertInstanceOf(Anthropic::class, $this->claude(baseUrl: $url));
        }

        foreach (['http://proxy.example.com', 'ftp://example.com', 'https://user:pass@example.com', 'not a url', 'https://example.com/?key=1'] as $url) {
            try {
                $this->claude(baseUrl: $url);
                $this->fail("Expected {$url} to be refused.");
            } catch (NotConfigured $exception) {
                $this->assertStringContainsString('The base URL for Anthropic must be an https:// address', $exception->getMessage());
            }
        }
    }

    public function test_a_refusal_and_an_overload_are_explained_in_plain_words(): void
    {
        $this->http->queueJson(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber']]);
        // Overloaded every time it is tried.
        foreach (range(1, 3) as $i) {
            $this->http->queueJson(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);
        }

        try {
            $this->claude()->text($this->request());
            $this->fail('Expected a refusal.');
        } catch (Refused $exception) {
            $this->assertSame('Claude declined this request. Try rewording it.', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }

        $this->assertSame('cyber', $this->logged('warning')[0]['context']['category']);

        try {
            $this->claude()->text($this->request());
            $this->fail('Expected an overload.');
        } catch (Overloaded $exception) {
            $this->assertSame('Anthropic is busy right now. Try again shortly.', $exception->getMessage());
            $this->assertSame([529, 'anthropic', true], [$exception->status(), $exception->provider(), $exception->retryable()]);
        }

        $this->assertCount(4, $this->http->requests);
    }

    public function test_a_refusal_with_some_text_is_returned_as_written(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'I can help with part of this.']], 'stop_reason' => 'refusal']);

        $response = $this->claude()->text($this->request());

        $this->assertSame(StopReason::Refusal, $response->stopReason);
        $this->assertSame('I can help with part of this.', $response->text);
    }

    public function test_a_refusal_naming_a_recommended_model_is_tried_once_on_it(): void
    {
        $this->http->queueJson([
            'model' => 'claude-opus-5-5',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['category' => 'bio', 'recommended_model' => 'claude-sonnet-4-6'],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]);
        $this->http->queueJson([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Answered.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
        ]);

        $response = $this->claude()->text($this->request(effort: 'low'));

        $this->assertSame(['Answered.', 'claude-sonnet-4-6'], [$response->text, $response->model]);
        $this->assertSame([22, 5], [$response->usage->input, $response->usage->output], 'The billed refusal still counts.');

        $retried = $this->http->body(1);
        $this->assertSame('claude-sonnet-4-6', $retried['model']);
        $this->assertSame(['effort' => 'low'], $retried['output_config']);
        $this->assertArrayNotHasKey('fallbacks', $retried, 'Sonnet 4.6 has no classifiers, so no fallback.');
        $this->assertFalse($this->http->requests[1]->hasHeader('anthropic-beta'));
    }

    public function test_a_recommended_model_that_also_refuses_is_reported(): void
    {
        $refusal = ['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['recommended_model' => 'claude-opus-5']];
        $this->http->queueJson($refusal);
        $this->http->queueJson($refusal);

        $this->expectException(Refused::class);

        try {
            $this->claude()->text($this->request());
        } finally {
            $this->assertCount(2, $this->http->requests, 'Tried once more, not again and again.');
        }
    }

    public function test_should_the_fallback_beta_be_refused_the_request_is_sent_without_it_from_then_on(): void
    {
        $this->http->queueJson(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Unknown anthropic-beta: server-side-fallback-2026-07-01']], 400);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'Again']]]);

        $this->assertSame('OK', $this->claude()->text($this->request())->text);

        $this->assertFalse($this->http->requests[1]->hasHeader('anthropic-beta'));
        $this->assertArrayNotHasKey('fallbacks', $this->http->body(1));
        $this->assertTrue(Anthropic::betaRefused());
        $this->assertStringContainsString('fallback beta', $this->logged('warning')[0]['message']);

        // Remembered for the rest of the process.
        $this->claude()->text($this->request());
        $this->assertFalse($this->http->requests[2]->hasHeader('anthropic-beta'));
    }

    public function test_another_bad_request_is_not_mistaken_for_the_beta(): void
    {
        $this->http->queueJson(['error' => ['message' => 'max_tokens: must be at most 128000']], 400);

        try {
            $this->claude()->text($this->request());
            $this->fail('Expected a bad response.');
        } catch (BadResponse $exception) {
            $this->assertSame('Anthropic said no (400): max_tokens: must be at most 128000', $exception->getMessage());
            $this->assertFalse($exception->retryable());
        }

        $this->assertCount(1, $this->http->requests);
        $this->assertFalse(Anthropic::betaRefused());
    }

    /**
     * @return iterable<string, array{string|null, StopReason}>
     */
    public static function stopReasons(): iterable
    {
        yield 'end_turn' => ['end_turn', StopReason::End];
        yield 'stop_sequence' => ['stop_sequence', StopReason::End];
        yield 'max_tokens' => ['max_tokens', StopReason::MaxTokens];
        yield 'context window' => ['model_context_window_exceeded', StopReason::MaxTokens];
        yield 'refusal' => ['refusal', StopReason::Refusal];
        yield 'pause_turn' => ['pause_turn', StopReason::Other];
        yield 'missing' => [null, StopReason::Other];
    }

    #[DataProvider('stopReasons')]
    public function test_stop_reasons_are_mapped(?string $reason, StopReason $expected): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'Some']], 'stop_reason' => $reason]);

        $response = $this->claude()->text($this->request());

        $this->assertSame($expected, $response->stopReason);
        $this->assertSame($expected === StopReason::MaxTokens, $response->truncated());
        $this->assertSame($expected === StopReason::MaxTokens, $response->truncated, 'The deprecated property agrees.');
    }

    public function test_a_reply_with_no_content_is_a_bad_response(): void
    {
        $this->http->queueJson(['id' => 'msg_1']);

        $this->expectException(BadResponse::class);
        $this->expectExceptionMessage('Anthropic sent back no content.');

        $this->claude()->text($this->request());
    }

    public function test_too_many_or_too_large_images_are_refused_before_sending(): void
    {
        foreach ([array_fill(0, 21, $this->png()), [new Image(str_repeat('x', 21 * 1024 * 1024), 'image/png')]] as $images) {
            try {
                $this->claude()->text($this->request(images: $images, agent: 'photo-picker'));
                $this->fail('Expected the request to be refused.');
            } catch (BadResponse $exception) {
                $this->assertStringContainsString('The photo-picker request has', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function test_bad_bytes_in_the_prompt_are_scrubbed_before_sending(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->claude()->text(new TextRequest('writer', "Caf\xE9", "Na\xEFve"));

        $this->assertTrue(mb_check_encoding((string) $this->http->body(0)['system'], 'UTF-8'));
    }

    public function test_every_failure_is_a_provider_exception(): void
    {
        $this->http->queue($this->http->response(200, 'not json'));

        try {
            $this->claude()->text($this->request());
            $this->fail('Expected an exception.');
        } catch (ProviderException $exception) {
            $this->assertInstanceOf(BadResponse::class, $exception);
            $this->assertSame('Anthropic sent back something that was not JSON.', $exception->getMessage());
        }

        $this->assertSame('A model call failed.', $this->logged('error')[0]['message']);
    }
}
