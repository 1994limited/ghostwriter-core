<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OpenAiTest extends ProviderTestCase
{
    private function openai(?string $baseUrl = null, ?string $model = null, ?string $imageModel = null): OpenAi
    {
        return new OpenAi('secret', $this->transport('openai'), $model, $imageModel, $baseUrl);
    }

    public function test_chatgpt_writes_and_makes_images_from_references(): void
    {
        $this->http->queueJson(['model' => 'gpt-6.1-sol-2026-08-01', 'choices' => [['message' => ['content' => 'Written.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]]);
        $this->http->queueJson(['data' => [['b64_json' => self::PNG]]]);
        $this->http->queueJson(['data' => [['b64_json' => self::PNG]]]);

        $openai = $this->openai();
        $response = $openai->text($this->request(images: [$this->png()]));

        $this->assertSame(['Written.', 9, 3], [$response->text, $response->usage->input, $response->usage->output]);
        $this->assertSame(['openai', 'gpt-6.1-sol-2026-08-01', StopReason::End], [$response->provider, $response->model, $response->stopReason]);

        $body = $this->http->body(0);
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $this->http->requests[0]->getUri());
        $this->assertSame('Bearer secret', $this->http->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame('gpt-6.1-sol', $body['model']);
        $this->assertSame(16000, $body['max_completion_tokens']);
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('data:image/png;base64,'.self::PNG, $body['messages'][3]['content'][1]['image_url']['url']);
        $this->assertArrayNotHasKey('reasoning_effort', $body, 'The writer leaves effort to the provider.');

        // With nothing to match, a plain generation; with references, an edit of them.
        $made = $openai->image(new ImageRequest('A lighthouse', shape: 'portrait'));
        $this->assertSame('image/png', $made->mime);
        $this->assertStringEndsWith('/v1/images/generations', (string) $this->http->requests[1]->getUri());
        $this->assertSame(['model' => 'gpt-image-2.5-sunburst', 'prompt' => 'A lighthouse', 'size' => '1024x1536', 'n' => 1], $this->http->body(1));

        $openai->image(new ImageRequest('A lighthouse', [$this->png()], Shape::Square));
        $edit = $this->http->requests[2];
        $this->assertStringEndsWith('/v1/images/edits', (string) $edit->getUri());
        $this->assertStringStartsWith('multipart/form-data; boundary=', $edit->getHeaderLine('content-type'));
        $this->assertStringContainsString('name="image[]"; filename="reference-0.png"', (string) $edit->getBody());
        $this->assertStringContainsString("name=\"size\"\r\n\r\n1024x1024\r\n", (string) $edit->getBody());
    }

    public function test_effort_is_sent_to_models_that_take_it(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);

        $this->openai()->text($this->request(agent: 'photo-researcher'));
        $this->openai(model: 'gpt-4.1')->text($this->request(agent: 'photo-researcher'));

        $this->assertSame(['low', 2000], [$this->http->body(0)['reasoning_effort'], $this->http->body(0)['max_completion_tokens']]);
        $this->assertArrayNotHasKey('reasoning_effort', $this->http->body(1));
        $this->assertSame('gpt-4.1', $this->http->body(1)['model'], 'The model chosen in the settings.');
    }

    public function test_a_gateway_gets_the_same_api(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);

        $this->openai(baseUrl: 'http://localhost:11434/v1/')->text($this->request(model: 'llama'));

        $this->assertSame('http://localhost:11434/v1/chat/completions', (string) $this->http->requests[0]->getUri());
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
    }

    #[DataProvider('stopReasons')]
    public function test_stop_reasons_are_mapped(?string $reason, StopReason $expected): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => 'Half a'], 'finish_reason' => $reason]]]);

        $this->assertSame($expected, $this->openai()->text($this->request())->stopReason);
    }

    public function test_a_refusal_or_a_filtered_empty_answer_is_explained(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => null, 'refusal' => "I can't help with that."], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]]);

        foreach (["ChatGPT declined this request: I can't help with that.", 'ChatGPT declined this request. Try rewording it.'] as $expected) {
            try {
                $this->openai()->text($this->request());
                $this->fail('Expected a refusal.');
            } catch (Refused $exception) {
                $this->assertSame($expected, $exception->getMessage());
            }
        }
    }

    public function test_a_request_that_is_simply_wrong_is_not_tried_again(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Incorrect API key provided: secret']], 401);

        try {
            $this->openai()->text($this->request());
            $this->fail('Expected the key to be refused.');
        } catch (AuthenticationFailed $exception) {
            $this->assertSame("OpenAI didn't accept the API key (401). Check it in Ghostwriter's Connections (or OPENAI_API_KEY in .env).", $exception->getMessage());
        }

        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->sleeper->waits);
    }

    public function test_an_answer_without_an_image_or_choices_is_a_bad_response(): void
    {
        $this->http->queueJson(['data' => []]);
        $this->http->queueJson(['data' => [['b64_json' => base64_encode('not an image')]]]);
        $this->http->queueJson(['object' => 'chat.completion']);

        foreach (['OpenAI did not send back an image.', 'OpenAI sent back an image that could not be read.'] as $expected) {
            try {
                $this->openai()->image(new ImageRequest('A lighthouse'));
                $this->fail('Expected a bad response.');
            } catch (BadResponse $exception) {
                $this->assertSame($expected, $exception->getMessage());
            }
        }

        $this->expectExceptionMessage('OpenAI sent back no answer.');
        $this->openai()->text($this->request());
    }

    public function test_too_many_reference_images_are_refused_before_sending(): void
    {
        $this->expectException(BadResponse::class);
        $this->expectExceptionMessage('The image request has 25 images; at most 24');

        try {
            $this->openai()->image(new ImageRequest('A lighthouse', array_fill(0, Limits::MAX_IMAGES + 1, $this->png())));
        } finally {
            $this->assertSame([], $this->http->requests);
        }
    }
}
