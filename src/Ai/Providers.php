<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ModelTiers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Connections\KeySources;
use Psr\Log\LoggerInterface;

/**
 * Chooses and builds the provider for each call, from the settings and from
 * which keys are set. Keys are read through Credentials every time and
 * never stored.
 *
 * Each addon builds one of these and shares it: a container singleton, or
 * a plugin component.
 *
 *     $providers->text()->text(new TextRequest('writer', $instructions, $prompt));
 *     $providers->image()?->image(new ImageRequest('A lighthouse'));
 *
 * In tests, fake() stands a FakeProvider in for every model; a fake marked
 * unconfigured() makes the registry behave as if the keys were missing.
 */
final class Providers
{
    /** How each provider is named in messages. */
    public const LABELS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Gemini',
        'openrouter' => 'OpenRouter',
    ];

    /** Providers that write. */
    public const TEXT = ['anthropic', 'openai', 'gemini', 'openrouter'];

    private ?FakeProvider $fake = null;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly HttpClients $http,
        private readonly ProviderSettings $settings,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Sleeper $sleeper = null,
        private readonly ?RetryPolicy $retry = null,
    ) {}

    /**
     * A copy that waits between retries with this sleeper. The registry is
     * immutable apart from faking, so rebind the copy where the original
     * was shared (a container singleton, a plugin component). A fake
     * standing in carries over.
     */
    public function withSleeper(Sleeper $sleeper): self
    {
        return $this->copy($sleeper, $this->retry);
    }

    /**
     * A copy that retries by this policy: how many attempts and the
     * longest wait. A fake standing in carries over.
     */
    public function withRetryPolicy(RetryPolicy $retry): self
    {
        return $this->copy($this->sleeper, $retry);
    }

    /**
     * The provider chosen to write with.
     *
     * @throws NotConfigured when it is unknown, has no key, or has a base URL core won't use.
     */
    public function text(): TextProvider
    {
        if ($this->fake !== null) {
            return $this->fake->textConfigured() ? $this->fake : throw $this->noKey($this->settings->textProvider());
        }

        $handle = $this->textHandle();

        if (! in_array($handle, self::TEXT, true)) {
            throw new NotConfigured("\"{$handle}\" is not a provider Ghostwriter can write with. Choose anthropic, openai, gemini or openrouter.", $handle);
        }

        $key = $this->credentials->key($handle) ?? throw $this->noKey($handle);

        return $this->build($handle, $key);
    }

    /**
     * The provider to make images with, or null when none has a key.
     *
     * @throws NotConfigured when its base URL is one core won't use.
     */
    public function image(): ?ImageProvider
    {
        if ($this->fake !== null) {
            return $this->fake->imageConfigured() ? $this->fake : null;
        }

        $handle = $this->imageHandle();
        $key = $handle === null ? null : $this->credentials->key($handle);

        if ($handle === null || $key === null) {
            return null;
        }

        $provider = $this->build($handle, $key);

        return $provider instanceof ImageProvider ? $provider : null;
    }

    /** The provider chosen to write with: anthropic, openai, gemini or openrouter (or fake). */
    public function textHandle(): string
    {
        return $this->fake !== null ? 'fake' : $this->settings->textProvider();
    }

    /**
     * The provider to make images with: the one chosen in the settings when
     * it has a key, or else the first in Models::IMAGE_ORDER that has one.
     * Null when there is none.
     */
    public function imageHandle(): ?string
    {
        if ($this->fake !== null) {
            return $this->fake->imageConfigured() ? 'fake' : null;
        }

        $chosen = $this->settings->imageProvider();

        foreach ($chosen ? [$chosen] : Models::IMAGE_ORDER as $provider) {
            if (in_array($provider, Models::IMAGE_ORDER, true) && $this->credentials->key($provider) !== null) {
                return $provider;
            }
        }

        return null;
    }

    /** Whether the chosen text provider has a key to call with. */
    public function configured(): bool
    {
        if ($this->fake !== null) {
            return $this->fake->textConfigured();
        }

        $handle = $this->settings->textProvider();

        return in_array($handle, self::TEXT, true) && $this->credentials->key($handle) !== null;
    }

    /**
     * Which keys are set, by environment variable, for a settings screen.
     * Never the keys themselves. While an unconfigured fake stands in,
     * every key is reported missing.
     *
     * @return array<string, bool>
     */
    public function keyStatus(): array
    {
        $status = [];
        $unconfigured = $this->fake !== null && (! $this->fake->textConfigured() || ! $this->fake->imageConfigured());

        foreach (Credentials::ENV as $provider => $variable) {
            $status[$variable] = ! $unconfigured && $this->credentials->key($provider) !== null;
        }

        return $status;
    }

    /**
     * Stand a fake in for every model, text and image alike, from now on.
     */
    public function fake(?FakeProvider $fake = null): FakeProvider
    {
        return $this->fake = $fake ?? new FakeProvider;
    }

    /** Stop faking. */
    public function unfake(): void
    {
        $this->fake = null;
    }

    public function faked(): bool
    {
        return $this->fake !== null;
    }

    private function copy(?Sleeper $sleeper, ?RetryPolicy $retry): self
    {
        $copy = new self($this->credentials, $this->http, $this->settings, $this->logger, $sleeper, $retry);
        $copy->fake = $this->fake;

        return $copy;
    }

    private function noKey(string $handle): NotConfigured
    {
        if ($handle === 'openrouter') {
            return new NotConfigured('OpenRouter isn\'t connected. Connect it in Ghostwriter\'s Connections (or set OPENROUTER_API_KEY in .env).', $handle);
        }

        if (! isset(self::LABELS[$handle], Credentials::ENV[$handle])) {
            return new NotConfigured("No API key is set for \"{$handle}\".", $handle);
        }

        return new NotConfigured('No API key is set for '.self::LABELS[$handle].'. Set it up in Ghostwriter\'s Connections (or set '.Credentials::ENV[$handle].' in .env).', $handle);
    }

    private function build(string $handle, string $key): TextProvider
    {
        $transport = new Transport($this->http, $handle, $this->retry, $this->sleeper, $this->logger);
        $baseUrl = $this->settings->baseUrl($handle);
        $timeout = $this->settings->timeout();
        $textModel = $this->settings->textProvider() === $handle ? $this->settings->textModel() : null;
        $imageModel = $this->imageModel($handle);

        return match ($handle) {
            'openrouter' => new OpenRouter($key, $transport, $textModel, $imageModel, $baseUrl, $timeout, $this->tierModels($handle), $this->credentials instanceof KeySources && in_array($this->credentials->source($handle), ['connected', 'stored'], true)),
            'openai' => new OpenAi($key, $transport, $textModel, $imageModel, $baseUrl, $timeout),
            'gemini' => new Gemini($key, $transport, $textModel, $imageModel, $baseUrl, $timeout),
            default => new Anthropic($key, $transport, $textModel, $baseUrl, $timeout, $this->settings->anthropicFallbacks()),
        };
    }

    /**
     * A model per tier, where the settings choose them (Ports\ModelTiers).
     *
     * @return array<string, string|null>
     */
    private function tierModels(string $handle): array
    {
        if (! $this->settings instanceof ModelTiers) {
            return [];
        }

        return [
            Agents::WRITING => $this->settings->tierModel($handle, Agents::WRITING),
            Agents::QUICK => $this->settings->tierModel($handle, Agents::QUICK),
        ];
    }

    /**
     * The image model chosen in the settings applies to the provider it
     * was chosen for: the configured image provider, or, when none is
     * configured, whichever is used.
     */
    private function imageModel(string $handle): ?string
    {
        $chosen = $this->settings->imageProvider();

        return $chosen === null || $chosen === $handle ? $this->settings->imageModel() : null;
    }
}
