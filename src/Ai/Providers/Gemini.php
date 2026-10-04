<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\JsonReply;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TakesSchemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * Gemini over the generateContent API, which also makes images when given
 * an image model.
 *
 * Parts marked `thought` are the model's working, not its answer, and are
 * dropped. Thinking tokens are billed, so they count as output.
 *
 * A request with a schema asks for JSON (`responseMimeType`) of that shape
 * (`responseJsonSchema`, Schemas::gemini()); a 400 refusing it sends the
 * request again as plain text.
 */
class Gemini extends HttpProvider implements ImageProvider, TakesSchemas, TextProvider
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta';

    public const RATIOS = ['landscape' => '3:2', 'portrait' => '2:3', 'square' => '1:1'];

    private const SAFETY = ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'IMAGE_SAFETY'];

    public function handle(): string
    {
        return 'gemini';
    }

    public function text(TextRequest $request): TextResponse
    {
        $started = microtime(true);
        $model = $this->textModel($request);

        $this->guard($request->agent, $request->images);

        $config = ['maxOutputTokens' => $request->resolvedMaxTokens()];
        $effort = $request->resolvedEffort();

        if ($effort !== null && Models::takesEffort('gemini', $model)) {
            $config['thinkingConfig'] = ['thinkingLevel' => $effort->value];
        }

        $mode = $this->structuredMode($request, $model);

        if ($mode !== null && $request->schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = Schemas::gemini($request->schema);
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->instructions]]],
            'contents' => [
                ...array_map(fn (Message $message) => ['role' => $message->role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $message->content]]], $request->history),
                ['role' => 'user', 'parts' => [...$this->inline($request->images), ['text' => $request->prompt]]],
            ],
            'generationConfig' => $config,
        ];

        try {
            $data = $this->generate($model, $body, $this->timeoutFor($request->timeout), $request->agent);
        } catch (BadResponse $exception) {
            if ($mode === null || ! $this->refusedTheSchema($exception)) {
                throw $exception;
            }

            $this->schemaDropped($request->agent, $model, $exception);
            unset($body['generationConfig']['responseMimeType'], $body['generationConfig']['responseJsonSchema']);
            $mode = null;
            $data = $this->generate($model, $body, $this->timeoutFor($request->timeout), $request->agent);
        }

        $candidate = $data['candidates'][0] ?? null;

        if (! is_array($candidate)) {
            throw new BadResponse('Gemini sent back no answer.', 'gemini');
        }

        $text = '';

        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && empty($part['thought'])) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        $stopReason = self::stopReason($candidate['finishReason'] ?? null);

        if ($stopReason === StopReason::Safety && trim($text) === '') {
            throw new Refused('Gemini declined this request. Try rewording it.', 'gemini');
        }

        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];

        return $this->finished($request->agent, new TextResponse(
            $text,
            $stopReason,
            new Usage(
                (int) ($usage['promptTokenCount'] ?? 0),
                (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            ),
            'gemini',
            is_string($data['modelVersion'] ?? null) && $data['modelVersion'] !== '' ? $data['modelVersion'] : $model,
            $mode !== null ? JsonReply::decode($text) : null,
            $mode,
        ), $started);
    }

    public function image(ImageRequest $request): Image
    {
        $this->guard('image', $request->references);

        $data = $this->generate($this->imageModelFor($request->model), [
            'contents' => [['role' => 'user', 'parts' => [...$this->inline($request->references), ['text' => $request->prompt]]]],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => ['aspectRatio' => self::RATIOS[$request->shape->value] ?? self::RATIOS[Shape::Landscape->value]],
            ],
        ], $this->timeoutFor($request->timeout), 'image');

        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;

            if (is_array($inline) && is_string($inline['data'] ?? null) && $inline['data'] !== '') {
                return $this->decodedImage($inline['data']);
            }
        }

        if (self::stopReason($data['candidates'][0]['finishReason'] ?? null) === StopReason::Safety) {
            throw new Refused('Gemini declined this request. Try rewording it.', 'gemini');
        }

        throw new BadResponse('Gemini did not send back an image.', 'gemini');
    }

    public static function stopReason(mixed $reason): StopReason
    {
        return match (true) {
            $reason === 'STOP' => StopReason::End,
            $reason === 'MAX_TOKENS' => StopReason::MaxTokens,
            in_array($reason, self::SAFETY, true) => StopReason::Safety,
            default => StopReason::Other,
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function generate(string $model, array $body, int $timeout, string $agent): array
    {
        $url = ($this->baseUrl ?? self::URL).'/models/'.rawurlencode($model).':generateContent';

        $data = $this->transport->json($url, ['x-goog-api-key' => $this->apiKey], $body, $timeout, $agent);

        // The prompt itself was blocked: there is no answer to read.
        $reason = $data['promptFeedback']['blockReason'] ?? null;

        if (is_string($reason) && $reason !== '') {
            throw new Refused("Gemini declined this request ({$reason}). Try rewording it.", 'gemini');
        }

        return $data;
    }

    /**
     * @param  array<int, Image>  $images
     * @return array<int, array<string, mixed>>
     */
    private function inline(array $images): array
    {
        return array_map(fn (Image $image) => ['inline_data' => ['mime_type' => $image->mime, 'data' => $image->base64()]], $images);
    }
}
