<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Multipart;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
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
 * ChatGPT over Chat Completions, and its image model over the Images API.
 *
 * Chat Completions rather than the Responses API, because the
 * OpenAI-compatible gateways a base URL points at almost all speak it.
 */
class OpenAi extends HttpProvider implements ImageProvider, TakesSchemas, TextProvider
{
    public const URL = 'https://api.openai.com/v1';

    public const SIZES = ['landscape' => '1536x1024', 'portrait' => '1024x1536', 'square' => '1024x1024'];

    public function handle(): string
    {
        return 'openai';
    }

    public function text(TextRequest $request): TextResponse
    {
        $started = microtime(true);
        $model = $this->textModel($request);

        $this->guard($request->agent, $request->images);

        $content = [['type' => 'text', 'text' => $request->prompt]];

        foreach ($request->images as $image) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$image->mime};base64,{$image->base64()}"]];
        }

        $body = [
            'model' => $model,
            'max_completion_tokens' => $request->resolvedMaxTokens(),
            'messages' => [
                ['role' => 'system', 'content' => $request->instructions],
                ...array_map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $effort = $request->resolvedEffort();

        if ($effort !== null && Models::takesEffort('openai', $model)) {
            $body['reasoning_effort'] = $effort->value;
        }

        $mode = $this->structuredMode($request, $model);

        if ($mode !== null && $request->schema !== null) {
            $body['response_format'] = self::responseFormat($request->schema, Schemas::strict($request->schema));
        }

        $data = $this->post($body, $request, $mode);

        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice)) {
            throw new BadResponse('OpenAI sent back no answer.', 'openai');
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $stopReason = self::stopReason($choice['finish_reason'] ?? null);

        if (! empty($message['refusal']) && trim($text) === '') {
            throw new Refused('ChatGPT declined this request: '.(is_string($message['refusal']) ? $message['refusal'] : 'no reason given.'), 'openai');
        }

        if ($stopReason === StopReason::Safety && trim($text) === '') {
            throw new Refused('ChatGPT declined this request. Try rewording it.', 'openai');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return $this->finished($request->agent, new TextResponse(
            $text,
            $stopReason,
            new Usage((int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0)),
            'openai',
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model,
            $mode !== null ? JsonReply::decode($text) : null,
            $mode,
        ), $started);
    }

    /**
     * With reference images the picture is made as an edit of them, which
     * is how the Images API takes images to work from.
     */
    public function image(ImageRequest $request): Image
    {
        $model = $this->imageModelFor($request->model);
        $size = self::SIZES[$request->shape->value] ?? self::SIZES[Shape::Landscape->value];
        $timeout = $this->timeoutFor($request->timeout);

        $this->guard('image', $request->references);

        if ($request->references === []) {
            $data = $this->transport->json($this->url('/images/generations'), $this->headers(), [
                'model' => $model,
                'prompt' => $request->prompt,
                'size' => $size,
                'n' => 1,
            ], $timeout, 'image');
        } else {
            $form = (new Multipart)
                ->add('model', $model)
                ->add('prompt', $request->prompt)
                ->add('size', $size);

            foreach ($request->references as $i => $reference) {
                $form->add('image[]', $reference->data, "reference-{$i}.{$reference->extension()}", $reference->mime);
            }

            $data = $this->transport->multipart($this->url('/images/edits'), $this->headers(), $form, $timeout, 'image');
        }

        $encoded = $data['data'][0]['b64_json'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw new BadResponse('OpenAI did not send back an image.', 'openai');
        }

        return $this->decodedImage($encoded);
    }

    /**
     * The chat completion, sent again without `response_format` if it is
     * refused (a gateway or model without it); $mode is then null.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(array $body, TextRequest $request, ?string &$mode): array
    {
        try {
            return $this->transport->json($this->url('/chat/completions'), $this->headers(), $body, $this->timeoutFor($request->timeout), $request->agent);
        } catch (BadResponse $exception) {
            if (! isset($body['response_format']) || ! $this->refusedTheSchema($exception)) {
                throw $exception;
            }

            $this->schemaDropped($request->agent, (string) $body['model'], $exception);
            unset($body['response_format']);
            $mode = null;

            return $this->transport->json($this->url('/chat/completions'), $this->headers(), $body, $this->timeoutFor($request->timeout), $request->agent);
        }
    }

    /**
     * Chat Completions' `response_format` for a schema, strict.
     *
     * @param  array<string, mixed>  $schema  Already rewritten for the model (Schemas).
     * @return array<string, mixed>
     */
    public static function responseFormat(OutputSchema $output, array $schema): array
    {
        return ['type' => 'json_schema', 'json_schema' => array_filter([
            'name' => $output->name,
            'description' => $output->description !== '' ? $output->description : null,
            'schema' => $schema,
            'strict' => true,
        ], fn ($value) => $value !== null)];
    }

    public static function stopReason(mixed $reason): StopReason
    {
        return match ($reason) {
            'stop' => StopReason::End,
            'length' => StopReason::MaxTokens,
            'content_filter' => StopReason::Safety,
            default => StopReason::Other,
        };
    }

    private function url(string $path): string
    {
        return ($this->baseUrl ?? self::URL).$path;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['authorization' => "Bearer {$this->apiKey}"];
    }
}
