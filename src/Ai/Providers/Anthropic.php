<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * Claude, over the Messages API.
 *
 * Server-side fallbacks: for models with refusal classifiers
 * (Models::takesFallbacks), a request a classifier declines is run again on
 * the model Anthropic recommends, rather than coming back empty. They are
 * sent only when `$fallbacks` is on and no base URL is set, since the
 * feature exists only on Anthropic's own API.
 *
 * Should the fallback beta be retired or renamed, the API refuses the
 * request outright (400, naming the beta). The request is then sent once
 * more without it, and the beta is left off for the rest of the process.
 */
class Anthropic extends HttpProvider implements TextProvider
{
    public const URL = 'https://api.anthropic.com';

    public const VERSION = '2023-06-01';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private static bool $betaRefused = false;

    public function __construct(
        string $apiKey,
        Transport $transport,
        ?string $model = null,
        ?string $baseUrl = null,
        int $timeout = 300,
        private readonly bool $fallbacks = true,
    ) {
        parent::__construct($apiKey, $transport, $model, null, $baseUrl, $timeout);
    }

    public function handle(): string
    {
        return 'anthropic';
    }

    /** Whether the fallback beta was refused earlier in this process. */
    public static function betaRefused(): bool
    {
        return self::$betaRefused;
    }

    /** Try the fallback beta again. For tests, and for long-running workers after an upgrade. */
    public static function resetBetaGuard(): void
    {
        self::$betaRefused = false;
    }

    public function text(TextRequest $request): TextResponse
    {
        $started = microtime(true);
        $model = $this->textModel($request);
        $timeout = $this->timeoutFor($request->timeout);

        $this->guard($request->agent, $request->images);

        $content = [];

        foreach ($request->images as $image) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image->mime, 'data' => $image->base64()]];
        }

        $content[] = ['type' => 'text', 'text' => $request->prompt];

        $body = [
            'model' => $model,
            'max_tokens' => $request->resolvedMaxTokens(),
            'system' => $request->instructions,
            'messages' => [
                ...array_map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];

        [$body, $headers] = $this->options($body, $headers, $model, $request);

        $data = $this->send($body, $headers, $timeout, $request->agent);
        $response = $this->read($data, $model);

        if ($response->stopReason === StopReason::Refusal && trim($response->text) === '') {
            $stopDetails = is_array($data['stop_details'] ?? null) ? $data['stop_details'] : [];
            $recommended = $stopDetails['recommended_model'] ?? null;

            $this->transport->logger()->warning('Claude declined a request.', [
                'agent' => $request->agent,
                'provider' => 'anthropic',
                'model' => $response->model,
                'category' => $stopDetails['category'] ?? null,
                'recommended_model' => $recommended,
            ]);

            // The API skipped the fallback (the recommended model was busy,
            // say) and named the model to try: try it, once.
            if (is_string($recommended) && $recommended !== '' && $recommended !== $model) {
                $body['model'] = $recommended;
                unset($body['output_config'], $body['fallbacks'], $headers['anthropic-beta']);
                [$body, $headers] = $this->options($body, $headers, $recommended, $request);

                $retried = $this->read($this->send($body, $headers, $timeout, $request->agent), $recommended);
                $response = new TextResponse($retried->text, $retried->stopReason, $response->usage->plus($retried->usage), 'anthropic', $retried->model);
            }

            if ($response->stopReason === StopReason::Refusal && trim($response->text) === '') {
                throw new Refused('Claude declined this request. Try rewording it.', 'anthropic');
            }
        }

        return $this->finished($request->agent, $response, $started);
    }

    /**
     * Effort and fallbacks, for the models that take them.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function options(array $body, array $headers, string $model, TextRequest $request): array
    {
        $effort = $request->resolvedEffort();

        if ($effort !== null && Models::takesEffort('anthropic', $model)) {
            $body['output_config'] = ['effort' => $effort->value];
        }

        if ($this->fallbacks && $this->baseUrl === null && ! self::$betaRefused && Models::takesFallbacks($model)) {
            $body['fallbacks'] = 'default';
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
        }

        return [$body, $headers];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(array $body, array $headers, int $timeout, string $agent): array
    {
        $url = ($this->baseUrl ?? self::URL).'/v1/messages';

        try {
            return $this->transport->json($url, $headers, $body, $timeout, $agent);
        } catch (BadResponse $exception) {
            if (! isset($headers['anthropic-beta']) || ! $this->refusedTheBeta($exception)) {
                throw $exception;
            }

            self::$betaRefused = true;

            $this->transport->logger()->warning('Anthropic refused the server-side fallback beta; sending without it from now on.', [
                'agent' => $agent,
                'provider' => 'anthropic',
                'model' => $body['model'] ?? null,
            ]);

            unset($headers['anthropic-beta'], $body['fallbacks']);

            return $this->transport->json($url, $headers, $body, $timeout, $agent);
        }
    }

    private function refusedTheBeta(BadResponse $exception): bool
    {
        if ($exception->status() !== 400) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'fallback') || str_contains($message, 'beta');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function read(array $data, string $model): TextResponse
    {
        if (! is_array($data['content'] ?? null)) {
            throw new BadResponse('Anthropic sent back no content.', 'anthropic');
        }

        $text = '';

        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new TextResponse(
            $text,
            self::stopReason($data['stop_reason'] ?? null),
            new Usage(
                (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
            ),
            'anthropic',
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model,
        );
    }

    public static function stopReason(mixed $reason): StopReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => StopReason::End,
            'max_tokens', 'model_context_window_exceeded' => StopReason::MaxTokens,
            'refusal' => StopReason::Refusal,
            default => StopReason::Other,
        };
    }
}
