<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\OutOfCredit;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Models from Anthropic, OpenAI, Google and others through one OpenRouter
 * key: text over its OpenAI-compatible Chat Completions, images over its
 * Image API (`/images`).
 *
 * The key is either OPENROUTER_API_KEY from the environment or one the site
 * got with "Connect with OpenRouter" (Credentials\OpenRouterConnection);
 * the environment's always wins. Requests and images pass through
 * OpenRouter on their way to the model's own provider.
 *
 * Each agent's default model depends on its tier (Agents::tier(),
 * Models::OPENROUTER_TIERS): a model chosen for the tier wins, then the
 * model chosen in the settings, then the tier's default.
 */
class OpenRouter extends HttpProvider implements ImageProvider, TextProvider
{
    public const URL = 'https://openrouter.ai/api/v1';

    /** Sent as HTTP-Referer, which OpenRouter uses to attribute calls to an app. */
    public const APP_URL = 'https://ghostwriterplugins.com';

    public const APP_TITLE = 'Ghostwriter';

    public const ASPECT_RATIOS = ['landscape' => '3:2', 'portrait' => '2:3', 'square' => '1:1'];

    public const CREDITS_URL = 'https://openrouter.ai/settings/credits';

    public const KEYS_URL = 'https://openrouter.ai/settings/keys';

    /**
     * @param  array<string, string|null>  $tierModels  A model per tier (Agents::WRITING, Agents::QUICK), or null for the default.
     * @param  bool  $connectedKey  Whether the key came from Connect with OpenRouter rather than the environment; it changes what a refused key's message tells the person to do.
     *
     * @throws NotConfigured for a base URL core won't send a key to.
     */
    public function __construct(
        #[SensitiveParameter] string $apiKey,
        Transport $transport,
        ?string $model = null,
        ?string $imageModel = null,
        ?string $baseUrl = null,
        int $timeout = 300,
        private readonly array $tierModels = [],
        private readonly bool $connectedKey = false,
        private readonly string $appUrl = self::APP_URL,
        private readonly string $appTitle = self::APP_TITLE,
    ) {
        parent::__construct($apiKey, $transport->withErrors(self::errorReader($connectedKey)), $model, $imageModel, $baseUrl, $timeout);
    }

    public function handle(): string
    {
        return 'openrouter';
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
            'max_tokens' => $request->resolvedMaxTokens(),
            'messages' => [
                ['role' => 'system', 'content' => $request->instructions],
                ...array_map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content], $request->history),
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $effort = $request->resolvedEffort();

        if ($effort !== null && Models::takesEffort('openrouter', $model)) {
            $body['reasoning'] = ['effort' => $effort->value];
        }

        $data = $this->transport->json($this->url('/chat/completions'), $this->headers(), $body, $this->timeoutFor($request->timeout), $request->agent);

        // OpenRouter can answer 200 with an error in place of a choice.
        if (is_array($data['error'] ?? null) && ! isset($data['choices'])) {
            throw $this->bodyError($data['error']);
        }

        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice)) {
            throw new BadResponse('OpenRouter sent back no answer.', 'openrouter');
        }

        if (($choice['finish_reason'] ?? null) === 'error') {
            throw $this->bodyError(is_array($choice['error'] ?? null) ? $choice['error'] : (is_array($data['error'] ?? null) ? $data['error'] : []));
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $stopReason = self::stopReason($choice['finish_reason'] ?? null);

        if (! empty($message['refusal']) && trim($text) === '') {
            throw new Refused('The model declined this request: '.(is_string($message['refusal']) ? $message['refusal'] : 'no reason given.'), 'openrouter');
        }

        if ($stopReason === StopReason::Safety && trim($text) === '') {
            throw new Refused('The model declined this request. Try rewording it.', 'openrouter');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return $this->finished($request->agent, new TextResponse(
            $text,
            $stopReason,
            new Usage((int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0)),
            'openrouter',
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model,
        ), $started);
    }

    /**
     * Over OpenRouter's Image API. Reference images go in as
     * `input_references`, as data URIs.
     */
    public function image(ImageRequest $request): Image
    {
        $model = $this->imageModelFor($request->model);

        $this->guard('image', $request->references);

        $body = [
            'model' => $model,
            'prompt' => $request->prompt,
            'aspect_ratio' => self::ASPECT_RATIOS[$request->shape->value] ?? self::ASPECT_RATIOS[Shape::Landscape->value],
            'n' => 1,
        ];

        if ($request->references !== []) {
            $body['input_references'] = array_map(fn (Image $reference) => [
                'type' => 'image_url',
                'image_url' => ['url' => "data:{$reference->mime};base64,{$reference->base64()}"],
            ], array_values($request->references));
        }

        $data = $this->transport->json($this->url('/images'), $this->headers(), $body, $this->timeoutFor($request->timeout), 'image');
        $encoded = $data['data'][0]['b64_json'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
            throw new BadResponse('OpenRouter did not send back an image.', 'openrouter');
        }

        return $this->decodedImage($encoded);
    }

    /**
     * OpenRouter normalises every model's reason to stop, `error` aside,
     * which the provider throws for.
     */
    public static function stopReason(mixed $reason): StopReason
    {
        return match ($reason) {
            'stop' => StopReason::End,
            'length' => StopReason::MaxTokens,
            'content_filter' => StopReason::Safety,
            default => StopReason::Other,
        };
    }

    /**
     * How OpenRouter's error responses read, for Transport: a refused key
     * (401), no credit (402), and moderation or a model's refusal (403,
     * which here never means a bad key). Anything else gets the usual
     * mapping.
     *
     * @return Closure(ResponseInterface, string): ?ProviderException
     */
    public static function errorReader(bool $connectedKey = false): Closure
    {
        return function (ResponseInterface $response, string $body) use ($connectedKey): ?ProviderException {
            $data = json_decode($body, true);
            $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

            return self::mapError($response->getStatusCode(), $error, $connectedKey);
        };
    }

    /**
     * @param  array<mixed>  $error  OpenRouter's `error`: code, message, metadata.
     */
    private static function mapError(int $status, array $error, bool $connectedKey): ?ProviderException
    {
        $metadata = is_array($error['metadata'] ?? null) ? $error['metadata'] : [];
        $type = is_string($metadata['error_type'] ?? null) ? $metadata['error_type'] : null;
        $said = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : null;

        return match (true) {
            $status === 401 => new AuthenticationFailed(
                $connectedKey
                    ? 'OpenRouter no longer accepts the key Ghostwriter was connected with (401). Connect with OpenRouter again in the settings.'
                    : 'OpenRouter didn\'t accept the API key (401). Check OPENROUTER_API_KEY.',
                'openrouter',
                $status,
            ),
            $status === 402 => match ($metadata['limit_source'] ?? null) {
                'openrouter_in_flight_budget' => new RateLimited('OpenRouter is holding new requests until earlier ones are paid for. Try again in a moment.', 'openrouter', $status),
                'openrouter_key_limit' => new OutOfCredit('This OpenRouter key has reached its spending limit. Raise the limit at '.self::KEYS_URL.', then try again.', 'openrouter', $status),
                default => new OutOfCredit('Your OpenRouter credit has run out. Add credit at '.self::CREDITS_URL.', then try again.', 'openrouter', $status),
            },
            $status === 403 && (isset($metadata['reasons']) || $type === 'content_policy_violation') => new Refused('OpenRouter\'s moderation flagged this request. Try rewording it.', 'openrouter', $status),
            $status === 403 && $type === 'refusal' => new Refused('The model declined this request. Try rewording it.', 'openrouter', $status),
            $status === 403 => new BadResponse('OpenRouter refused this request (403)'.($said !== null ? ": {$said}" : '.'), 'openrouter', $status),
            default => null,
        };
    }

    /**
     * An error OpenRouter put in a 200's body (or in a choice), once the
     * model's provider failed after OpenRouter had answered.
     *
     * @param  array<mixed>  $error
     */
    private function bodyError(array $error): ProviderException
    {
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $said = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : null;
        $mapped = $code !== null ? self::mapError($code, $error, $this->connectedKey) : null;

        if ($mapped !== null) {
            return $mapped;
        }

        if ($code === 429) {
            return new RateLimited('OpenRouter is limiting requests. Try again in a minute.', 'openrouter', $code);
        }

        return new BadResponse(
            str_replace($this->apiKey, '[redacted]', 'OpenRouter could not finish the answer'.($said !== null ? ": {$said}" : '.')),
            'openrouter',
            $code,
            null,
            $code === null || $code >= 500 || $code === 408,
        );
    }

    /**
     * The model for this request: the request's own, the one chosen for
     * the agent's tier, the one chosen in the settings, then the tier's
     * default.
     */
    protected function textModel(TextRequest $request): string
    {
        $tier = $this->tierModels[Agents::tier($request->agent)] ?? null;

        return ($request->model ?: $tier) ?: (($this->model ?: null) ?? Models::defaultTextFor('openrouter', $request->agent));
    }

    /**
     * The key stays out of var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['handle' => 'openrouter', 'apiKey' => '[redacted]', 'model' => $this->model, 'imageModel' => $this->imageModel, 'baseUrl' => $this->baseUrl, 'tierModels' => $this->tierModels, 'connectedKey' => $this->connectedKey];
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
        return [
            'authorization' => "Bearer {$this->apiKey}",
            'HTTP-Referer' => $this->appUrl,
            'X-OpenRouter-Title' => $this->appTitle,
            'X-Title' => $this->appTitle,
        ];
    }
}
