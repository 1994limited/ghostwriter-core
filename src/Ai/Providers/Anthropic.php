<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\JsonReply;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TakesSchemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * Claude, over the Messages API.
 *
 * Prompt caching: the instructions of the agents in Agents::CACHED are
 * sent as a system block marked `cache_control` (ephemeral), so a review's
 * calls after the first read them from the cache.
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
 *
 * Structured output: a request with a schema goes to the models that have
 * it (Models::structuredOutput()) as `output_config.format` (json_schema,
 * Schemas::anthropic()), beside the effort. Older Claude models get a tool,
 * `reply`, whose input is the schema, chosen for them (or, on a model that
 * refuses a forced tool_choice, asked for in the prompt); its input is the
 * reply. Either way the reply's JSON is the response's text, and decoded as
 * `structured`. A 400 that refuses the format sends the request again
 * without it, as plain text, once.
 */
class Anthropic extends HttpProvider implements TakesSchemas, TextProvider
{
    public const URL = 'https://api.anthropic.com';

    public const VERSION = '2023-06-01';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** The tool an older Claude model answers through when a schema is asked for. */
    public const REPLY_TOOL = 'reply';

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
            'system' => Agents::cachesInstructions($request->agent)
                ? [['type' => 'text', 'text' => $request->instructions, 'cache_control' => ['type' => 'ephemeral']]]
                : $request->instructions,
            'messages' => [
                ...array_map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];

        $mode = $this->structuredMode($request, $model);
        [$body, $headers] = $this->options($body, $headers, $model, $request, $mode);

        $data = $this->send($body, $headers, $timeout, $request->agent, $mode);
        $response = $this->read($data, $model, $mode);

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
                unset($body['output_config'], $body['fallbacks'], $body['tools'], $body['tool_choice'], $headers['anthropic-beta']);
                $body['messages'][array_key_last($body['messages'])]['content'] = $content;
                $mode = $this->structuredMode($request, $recommended);
                [$body, $headers] = $this->options($body, $headers, $recommended, $request, $mode);

                $data = $this->send($body, $headers, $timeout, $request->agent, $mode);
                $retried = $this->read($data, $recommended, $mode);
                $response = $retried->withUsage($response->usage->plus($retried->usage));
            }

            if ($response->stopReason === StopReason::Refusal && trim($response->text) === '') {
                throw new Refused('Claude declined this request. Try rewording it.', 'anthropic');
            }
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return $this->finished($request->agent, $response, $started, [
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
        ]);
    }

    /**
     * Effort, the reply's format and fallbacks, for the models that take them.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function options(array $body, array $headers, string $model, TextRequest $request, ?string $mode): array
    {
        $effort = $request->resolvedEffort();
        $config = [];

        if ($effort !== null && Models::takesEffort('anthropic', $model)) {
            $config['effort'] = $effort->value;
        }

        if ($mode === TextResponse::JSON_SCHEMA && $request->schema !== null) {
            $config['format'] = ['type' => 'json_schema', 'schema' => Schemas::anthropic($request->schema)];
        }

        if ($config !== []) {
            $body['output_config'] = $config;
        }

        if ($mode === TextResponse::TOOL && $request->schema !== null) {
            $body['tools'] = [[
                'name' => self::REPLY_TOOL,
                'description' => trim('Send your reply. '.$request->schema->description),
                'input_schema' => Schemas::anthropic($request->schema),
            ]];

            if (Models::refusesForcedTools($model)) {
                $body['tool_choice'] = ['type' => 'auto'];
                $last = array_key_last($body['messages']);
                $body['messages'][$last]['content'][] = ['type' => 'text', 'text' => 'Send your reply by calling the `'.self::REPLY_TOOL.'` tool, once, with nothing else.'];
            } else {
                $body['tool_choice'] = ['type' => 'tool', 'name' => self::REPLY_TOOL];
            }
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
    private function send(array $body, array $headers, int $timeout, string $agent, ?string &$mode = null): array
    {
        $url = ($this->baseUrl ?? self::URL).'/v1/messages';

        try {
            return $this->transport->json($url, $headers, $body, $timeout, $agent);
        } catch (BadResponse $exception) {
            if ($mode !== null && ! ($this->refusedTheBeta($exception) && isset($headers['anthropic-beta'])) && $this->refusedTheSchema($exception)) {
                $this->schemaDropped($agent, (string) ($body['model'] ?? ''), $exception);
                unset($body['output_config']['format'], $body['tools'], $body['tool_choice']);
                $mode = null;

                if (($body['output_config'] ?? null) === []) {
                    unset($body['output_config']);
                }

                return $this->send($body, $headers, $timeout, $agent);
            }

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
    private function read(array $data, string $model, ?string $mode = null): TextResponse
    {
        if (! is_array($data['content'] ?? null)) {
            throw new BadResponse('Anthropic sent back no content.', 'anthropic');
        }

        $text = '';
        $input = null;

        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }

            if ($mode === TextResponse::TOOL && is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::REPLY_TOOL && is_array($block['input'] ?? null)) {
                $input = $block['input'];
            }
        }

        $stopReason = self::stopReason($data['stop_reason'] ?? null);

        if ($input !== null) {
            // The tool's input is the reply; calling it is how the model finished.
            $text = (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stopReason = $stopReason === StopReason::Other ? StopReason::End : $stopReason;
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new TextResponse(
            $text,
            $stopReason,
            new Usage(
                (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
            ),
            'anthropic',
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model,
            $mode !== null ? ($input ?? JsonReply::decode($text)) : null,
            $mode,
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
