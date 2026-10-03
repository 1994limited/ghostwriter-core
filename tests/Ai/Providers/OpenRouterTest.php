<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\OutOfCredit;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OpenRouter over a mocked network. No call reaches OpenRouter; the shapes
 * follow its docs (openrouter.ai/docs, checked 2026-10-03).
 */
class OpenRouterTest extends ProviderTestCase
{
    private const KEY = 'sk-or-v1-0123456789abcdef';

    /**
     * @param  array<string, string|null>  $tiers
     */
    private function openrouter(?string $model = null, ?string $imageModel = null, ?string $baseUrl = null, array $tiers = [], bool $connected = false): OpenRouter
    {
        return new OpenRouter(self::KEY, $this->transport('openrouter'), $model, $imageModel, $baseUrl, 300, $tiers, $connected);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function answer(string $text = 'OK', string $reason = 'stop', array $extra = []): array
    {
        return $extra + ['model' => 'anthropic/claude-opus-5.5', 'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => $reason]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4]];
    }

    public function test_it_writes_over_chat_completions_with_images_and_attribution(): void
    {
        $this->http->queueJson($this->answer('Written.'));

        $response = $this->openrouter()->text($this->request(images: [$this->png()]));

        $this->assertSame(['Written.', 12, 4, StopReason::End], [$response->text, $response->usage->input, $response->usage->output, $response->stopReason]);
        $this->assertSame(['openrouter', 'anthropic/claude-opus-5.5'], [$response->provider, $response->model]);

        $request = $this->http->requests[0];
        $body = $this->http->body(0);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer '.self::KEY, $request->getHeaderLine('Authorization'));
        $this->assertSame(OpenRouter::APP_URL, $request->getHeaderLine('HTTP-Referer'));
        $this->assertSame('Ghostwriter', $request->getHeaderLine('X-OpenRouter-Title'));
        $this->assertSame('Ghostwriter', $request->getHeaderLine('X-Title'));
        $this->assertSame('anthropic/claude-opus-5.5', $body['model'], 'The writing tier\'s default.');
        $this->assertSame(16000, $body['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
        $this->assertArrayNotHasKey('reasoning', $body, 'The writer leaves effort to the model.');
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('Be brief.', $body['messages'][0]['content']);
        $this->assertSame(['type' => 'text', 'text' => 'Say hello.'], $body['messages'][3]['content'][0]);
        $this->assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PNG]], $body['messages'][3]['content'][1]);
    }

    public function test_each_tier_has_its_default_and_choices_win_in_order(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->http->queueJson($this->answer());
        }

        $this->openrouter()->text($this->request(agent: 'photo-picker'));
        $this->openrouter(model: 'openai/gpt-6.1-sol')->text($this->request(agent: 'photo-picker'));
        $this->openrouter(model: 'openai/gpt-6.1-sol', tiers: [Agents::QUICK => 'google/gemini-3.8-flash'])->text($this->request(agent: 'photo-picker'));
        $this->openrouter(model: 'openai/gpt-6.1-sol', tiers: [Agents::QUICK => 'google/gemini-3.8-flash'])->text($this->request(agent: 'writer'));
        $this->openrouter(tiers: [Agents::QUICK => 'google/gemini-3.8-flash'])->text($this->request(model: 'x/own', agent: 'photo-picker'));
        $this->openrouter(tiers: [Agents::WRITING => null])->text($this->request());

        $this->assertSame(
            ['anthropic/claude-sonnet-5.5', 'openai/gpt-6.1-sol', 'google/gemini-3.8-flash', 'openai/gpt-6.1-sol', 'x/own', 'anthropic/claude-opus-5.5'],
            array_map(fn (int $i) => $this->http->body($i)['model'], range(0, 5)),
        );

        // The quick tier thinks little, where the model takes effort.
        $this->assertSame(['effort' => 'low'], $this->http->body(0)['reasoning']);
        $this->assertSame(2000, $this->http->body(0)['max_tokens']);
        $this->assertSame(['effort' => 'low'], $this->http->body(2)['reasoning']);
        $this->assertArrayNotHasKey('reasoning', $this->http->body(4), 'An id OpenRouter maps to no known model gets no effort.');
    }

    public function test_it_makes_images_over_the_image_api(): void
    {
        $this->http->queueJson(['created' => 1, 'data' => [['b64_json' => self::PNG, 'media_type' => 'image/png']]]);
        $this->http->queueJson(['data' => [['b64_json' => self::PNG]]]);

        $openrouter = $this->openrouter();
        $made = $openrouter->image(new ImageRequest('A lighthouse', shape: Shape::Portrait));
        $openrouter->image(new ImageRequest('A lighthouse', [$this->png()], Shape::Square, model: 'google/gemini-3.1-flash-image'));

        $this->assertSame('image/png', $made->mime);
        $this->assertSame('https://openrouter.ai/api/v1/images', (string) $this->http->requests[0]->getUri());
        $this->assertSame(['model' => 'openai/gpt-image-2.5-sunburst', 'prompt' => 'A lighthouse', 'aspect_ratio' => '2:3', 'n' => 1], $this->http->body(0));
        $this->assertSame('google/gemini-3.1-flash-image', $this->http->body(1)['model']);
        $this->assertSame('1:1', $this->http->body(1)['aspect_ratio']);
        $this->assertSame([['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PNG]]], $this->http->body(1)['input_references']);
    }

    public function test_an_image_answer_without_an_image_is_a_bad_response(): void
    {
        $this->http->queueJson(['data' => []]);

        $this->expectException(BadResponse::class);
        $this->expectExceptionMessage('OpenRouter did not send back an image.');

        $this->openrouter()->image(new ImageRequest('A lighthouse'));
    }

    /**
     * @return iterable<string, array{string|null, StopReason}>
     */
    public static function stopReasons(): iterable
    {
        yield 'stop' => ['stop', StopReason::End];
        yield 'length' => ['length', StopReason::MaxTokens];
        yield 'content_filter' => ['content_filter', StopReason::Safety];
        yield 'tool_calls' => ['tool_calls', StopReason::Other];
        yield 'missing' => [null, StopReason::Other];
    }

    #[DataProvider('stopReasons')]
    public function test_stop_reasons_are_mapped(?string $reason, StopReason $expected): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => 'Half a'], 'finish_reason' => $reason]]]);

        $this->assertSame($expected, $this->openrouter()->text($this->request())->stopReason);
    }

    public function test_a_refusal_or_a_filtered_empty_answer_is_explained(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => null, 'refusal' => 'No.'], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]]);

        foreach (['The model declined this request: No.', 'The model declined this request. Try rewording it.'] as $expected) {
            try {
                $this->openrouter()->text($this->request());
                $this->fail('Expected a refusal.');
            } catch (Refused $exception) {
                $this->assertSame($expected, $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, class-string<ProviderException>, string, bool}>
     */
    public static function errors(): iterable
    {
        yield 'no credit' => [402, ['code' => 402, 'message' => 'Insufficient credits', 'metadata' => ['limit_source' => 'openrouter_credits']], OutOfCredit::class, 'Your OpenRouter credit has run out. Add credit at https://openrouter.ai/settings/credits, then try again.', false];
        yield 'no credit, no metadata' => [402, ['code' => 402, 'message' => 'Insufficient credits'], OutOfCredit::class, 'Your OpenRouter credit has run out. Add credit at https://openrouter.ai/settings/credits, then try again.', false];
        yield 'key limit' => [402, ['code' => 402, 'message' => 'Key limit exceeded', 'metadata' => ['limit_source' => 'openrouter_key_limit']], OutOfCredit::class, 'This OpenRouter key has reached its spending limit. Raise the limit at https://openrouter.ai/settings/keys, then try again.', false];
        yield 'in-flight budget' => [402, ['code' => 402, 'message' => 'Budget', 'metadata' => ['limit_source' => 'openrouter_in_flight_budget']], RateLimited::class, 'OpenRouter is holding new requests until earlier ones are paid for. Try again in a moment.', true];
        yield 'env key refused' => [401, ['code' => 401, 'message' => 'No auth credentials found'], AuthenticationFailed::class, 'OpenRouter didn\'t accept the API key (401). Check OPENROUTER_API_KEY.', false];
        yield 'moderation' => [403, ['code' => 403, 'message' => 'Input flagged', 'metadata' => ['reasons' => ['violence'], 'flagged_input' => '…', 'provider_name' => 'OpenAI', 'model_slug' => 'openai/gpt-6.1-sol']], Refused::class, 'OpenRouter\'s moderation flagged this request. Try rewording it.', false];
        yield 'model refusal' => [403, ['code' => 403, 'message' => 'Refused', 'metadata' => ['error_type' => 'refusal']], Refused::class, 'The model declined this request. Try rewording it.', false];
        yield 'guardrail' => [403, ['code' => 403, 'message' => 'Blocked by a guardrail', 'metadata' => ['error_type' => 'permission_denied']], BadResponse::class, 'OpenRouter refused this request (403): Blocked by a guardrail', false];
        yield 'unknown model' => [400, ['code' => 400, 'message' => 'x/none is not a valid model ID'], BadResponse::class, 'OpenRouter said no (400): x/none is not a valid model ID', false];
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  class-string<ProviderException>  $class
     */
    #[DataProvider('errors')]
    public function test_errors_are_said_plainly(int $status, array $error, string $class, string $message, bool $retryable): void
    {
        $this->http->queueJson(['error' => $error], $status);

        try {
            $this->openrouter()->text($this->request());
            $this->fail("Expected {$class}.");
        } catch (ProviderException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame([$message, $status, 'openrouter', $retryable], [$exception->getMessage(), $exception->status(), $exception->provider(), $exception->retryable()]);
        }

        $this->assertCount(1, $this->http->requests, 'None of these is tried again.');
        $this->assertSame([], $this->sleeper->waits);
    }

    public function test_a_refused_connected_key_says_to_connect_again(): void
    {
        $this->http->queueJson(['error' => ['code' => 401, 'message' => 'User not found.']], 401);

        $this->expectException(AuthenticationFailed::class);
        $this->expectExceptionMessage('OpenRouter no longer accepts the key Ghostwriter was connected with (401). Connect with OpenRouter again in the settings.');

        $this->openrouter(connected: true)->text($this->request());
    }

    public function test_busy_and_rate_limited_calls_are_retried_as_for_the_others(): void
    {
        $this->http->queueJson(['error' => ['code' => 429, 'message' => 'Rate limited']], 429, ['retry-after' => '2']);
        $this->http->queueJson(['error' => ['code' => 502, 'message' => 'Provider returned error']], 502);
        $this->http->queueJson($this->answer('At last.'));

        $this->assertSame('At last.', $this->openrouter()->text($this->request())->text);
        $this->assertCount(3, $this->http->requests);
        $this->assertSame(2.0, $this->sleeper->waits[0]);
    }

    public function test_an_error_inside_a_200_is_not_taken_for_an_answer(): void
    {
        $this->http->queueJson(['error' => ['code' => 402, 'message' => 'Insufficient credits']]);
        $this->http->queueJson(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Upstream failed']]]]);

        try {
            $this->openrouter()->text($this->request());
            $this->fail('Expected OutOfCredit.');
        } catch (OutOfCredit $exception) {
            $this->assertStringContainsString('credit has run out', $exception->getMessage());
        }

        try {
            $this->openrouter()->text($this->request());
            $this->fail('Expected a bad response.');
        } catch (BadResponse $exception) {
            $this->assertSame('OpenRouter could not finish the answer: Upstream failed', $exception->getMessage());
            $this->assertTrue($exception->retryable());
        }
    }

    public function test_the_key_never_reaches_a_message_a_log_or_a_dump(): void
    {
        $this->http->queueJson(['error' => ['code' => 500, 'message' => 'Bad key '.self::KEY]], 500);
        $this->http->queueJson(['error' => ['code' => 500, 'message' => 'Bad key '.self::KEY]], 500);
        $this->http->queueJson(['error' => ['code' => 500, 'message' => 'Bad key '.self::KEY]], 500);

        $openrouter = $this->openrouter();

        try {
            $openrouter->text($this->request());
            $this->fail('Expected a bad response.');
        } catch (BadResponse $exception) {
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }

        $this->assertStringNotContainsString(self::KEY, (string) json_encode($this->logs));
        $this->assertStringNotContainsString(self::KEY, print_r($openrouter, true));
    }

    public function test_a_gateway_and_the_request_size_guard_apply(): void
    {
        $this->http->queueJson($this->answer());

        $this->openrouter(baseUrl: 'https://gateway.example.com/openrouter/v1/')->text($this->request());
        $this->assertSame('https://gateway.example.com/openrouter/v1/chat/completions', (string) $this->http->requests[0]->getUri());

        $this->expectException(BadResponse::class);
        $this->openrouter()->text($this->request(images: array_fill(0, Limits::MAX_IMAGES + 1, $this->png())));
    }
}
