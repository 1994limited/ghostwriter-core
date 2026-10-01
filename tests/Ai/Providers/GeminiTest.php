<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GeminiTest extends ProviderTestCase
{
    private function gemini(?string $baseUrl = null): Gemini
    {
        return new Gemini('secret', $this->transport('gemini'), baseUrl: $baseUrl);
    }

    public function test_gemini_writes_and_makes_images(): void
    {
        $this->http->queueJson([
            'candidates' => [['content' => ['parts' => [['text' => 'Thinking…', 'thought' => true], ['text' => 'Written.']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 30],
            'modelVersion' => 'gemini-3.8-flash-001',
        ]);
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => self::PNG]]]]]]]);
        $this->http->queueJson(['promptFeedback' => ['blockReason' => 'SAFETY']]);

        $gemini = $this->gemini();
        $response = $gemini->text($this->request(images: [$this->png()]));

        $this->assertSame('Written.', $response->text);
        $this->assertSame([7, 32], [$response->usage->input, $response->usage->output], 'Thinking is billed, so it counts.');
        $this->assertSame(['gemini', 'gemini-3.8-flash-001', StopReason::End], [$response->provider, $response->model, $response->stopReason]);

        $request = $this->http->requests[0];
        $body = $this->http->body(0);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame(['user', 'model', 'user'], array_column($body['contents'], 'role'));
        $this->assertSame(['inline_data' => ['mime_type' => 'image/png', 'data' => self::PNG]], $body['contents'][2]['parts'][0]);
        $this->assertSame('Be brief.', $body['systemInstruction']['parts'][0]['text']);
        $this->assertSame(['maxOutputTokens' => 16000], $body['generationConfig']);

        $this->assertSame('image/png', $gemini->image(new ImageRequest('A lighthouse', shape: 'square'))->mime);
        $this->assertStringEndsWith('/models/gemini-3.1-flash-image:generateContent', (string) $this->http->requests[1]->getUri());
        $this->assertSame(['responseModalities' => ['IMAGE'], 'imageConfig' => ['aspectRatio' => '1:1']], $this->http->body(1)['generationConfig']);

        $this->expectException(Refused::class);
        $this->expectExceptionMessage('Gemini declined this request (SAFETY)');
        $gemini->text($this->request());
    }

    public function test_effort_becomes_a_thinking_level(): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]]);

        $this->gemini()->text($this->request(agent: 'photo-picker'));

        $this->assertSame(['maxOutputTokens' => 2000, 'thinkingConfig' => ['thinkingLevel' => 'low']], $this->http->body(0)['generationConfig']);
    }

    public function test_a_gateway_gets_the_same_api(): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]]);

        $this->gemini('https://gateway.example.com/google/v1beta')->text($this->request());

        $this->assertSame('https://gateway.example.com/google/v1beta/models/gemini-3.8-flash:generateContent', (string) $this->http->requests[0]->getUri());
    }

    /**
     * @return iterable<string, array{string|null, StopReason}>
     */
    public static function stopReasons(): iterable
    {
        yield 'STOP' => ['STOP', StopReason::End];
        yield 'MAX_TOKENS' => ['MAX_TOKENS', StopReason::MaxTokens];
        yield 'SAFETY' => ['SAFETY', StopReason::Safety];
        yield 'PROHIBITED_CONTENT' => ['PROHIBITED_CONTENT', StopReason::Safety];
        yield 'BLOCKLIST' => ['BLOCKLIST', StopReason::Safety];
        yield 'SPII' => ['SPII', StopReason::Safety];
        yield 'IMAGE_SAFETY' => ['IMAGE_SAFETY', StopReason::Safety];
        yield 'RECITATION' => ['RECITATION', StopReason::Other];
    }

    #[DataProvider('stopReasons')]
    public function test_stop_reasons_are_mapped(?string $reason, StopReason $expected): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'Half a']]], 'finishReason' => $reason]]]);

        $this->assertSame($expected, $this->gemini()->text($this->request())->stopReason);
    }

    public function test_a_safety_stop_with_nothing_written_is_a_refusal(): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'PROHIBITED_CONTENT']]]);
        $this->http->queueJson(['candidates' => [['finishReason' => 'IMAGE_SAFETY']]]);

        foreach ([fn () => $this->gemini()->text($this->request()), fn () => $this->gemini()->image(new ImageRequest('A lighthouse'))] as $call) {
            try {
                $call();
                $this->fail('Expected a refusal.');
            } catch (Refused $exception) {
                $this->assertSame('Gemini declined this request. Try rewording it.', $exception->getMessage());
            }
        }
    }

    public function test_an_answer_without_a_candidate_or_image_is_a_bad_response(): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'No picture, sorry.']]], 'finishReason' => 'STOP']]]);
        $this->http->queueJson(['usageMetadata' => []]);

        try {
            $this->gemini()->image(new ImageRequest('A lighthouse'));
            $this->fail('Expected a bad response.');
        } catch (BadResponse $exception) {
            $this->assertSame('Gemini did not send back an image.', $exception->getMessage());
        }

        $this->expectExceptionMessage('Gemini sent back no answer.');
        $this->gemini()->text($this->request());
    }
}
