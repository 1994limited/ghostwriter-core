<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use InvalidArgumentException;

/**
 * The model catalogue: which model each provider uses when none is chosen,
 * and what each model accepts. Nothing else names a model, so this is the
 * one place to update when providers retire or add models.
 *
 * Defaults checked against each provider's model list on 2026-10-01.
 */
final class Models
{
    public const TEXT_DEFAULTS = [
        'anthropic' => 'claude-opus-5-5',
        'openai' => 'gpt-6.1-sol',
        'gemini' => 'gemini-3.8-flash',
        'openrouter' => 'anthropic/claude-opus-5.5',
    ];

    public const IMAGE_DEFAULTS = [
        'openai' => 'gpt-image-2.5-sunburst',
        'gemini' => 'gemini-3.1-flash-image',
        'openrouter' => 'openai/gpt-image-2.5-sunburst',
    ];

    /**
     * Providers that make images, in the order they are tried when none is
     * chosen. OpenRouter comes last, so a site with an OpenAI or Gemini key
     * keeps making images with it.
     */
    public const IMAGE_ORDER = ['openai', 'gemini', 'openrouter'];

    /**
     * OpenRouter's default model for each tier of agent (Agents::tier()):
     * the writing jobs, and the quick ones (photo search and choice, gap
     * fixes). Checked against openrouter.ai/models on 2026-10-03. A site can
     * choose its own per tier (Ports\ModelTiers) or one for every agent
     * (ProviderSettings::textModel()).
     */
    public const OPENROUTER_TIERS = [
        Agents::WRITING => 'anthropic/claude-opus-5.5',
        Agents::QUICK => 'anthropic/claude-sonnet-5.5',
    ];

    /**
     * Text models to offer in a settings dropdown when OpenRouter is the
     * provider, by OpenRouter model id. Any other id OpenRouter lists works
     * too. Checked against openrouter.ai/models on 2026-10-03.
     */
    public const OPENROUTER_TEXT_CHOICES = [
        'anthropic/claude-opus-5.5' => 'Claude Opus 5.5 (Anthropic)',
        'anthropic/claude-sonnet-5.5' => 'Claude Sonnet 5.5 (Anthropic)',
        'openai/gpt-6.1-sol' => 'GPT-6.1 Sol (OpenAI)',
        'google/gemini-3.8-flash' => 'Gemini 3.8 Flash (Google)',
    ];

    /** Image models to offer when OpenRouter makes images. */
    public const OPENROUTER_IMAGE_CHOICES = [
        'openai/gpt-image-2.5-sunburst' => 'GPT Image 2.5 Sunburst (OpenAI)',
        'openai/gpt-image-2.5-flare' => 'GPT Image 2.5 Flare (OpenAI, faster)',
        'google/gemini-3.1-flash-image' => 'Gemini 3.1 Flash Image (Google)',
        'google/gemini-3-pro-image' => 'Gemini 3 Pro Image (Google)',
    ];

    /**
     * Anthropic models with refusal classifiers, which take server-side
     * fallbacks. Dated snapshots of these match too.
     */
    public const ANTHROPIC_FALLBACK_MODELS = ['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    /**
     * @throws InvalidArgumentException for a provider that doesn't write.
     */
    public static function defaultText(string $provider): string
    {
        return self::TEXT_DEFAULTS[$provider] ?? throw new InvalidArgumentException("\"{$provider}\" is not a provider Ghostwriter can write with.");
    }

    /**
     * The default model for one agent: the tier's model on OpenRouter, the
     * provider's one default elsewhere.
     *
     * @throws InvalidArgumentException for a provider that doesn't write.
     */
    public static function defaultTextFor(string $provider, string $agent): string
    {
        if ($provider === 'openrouter') {
            return self::OPENROUTER_TIERS[Agents::tier($agent)] ?? self::defaultText($provider);
        }

        return self::defaultText($provider);
    }

    /**
     * @throws InvalidArgumentException for a provider that doesn't make images.
     */
    public static function defaultImage(string $provider): string
    {
        return self::IMAGE_DEFAULTS[$provider] ?? throw new InvalidArgumentException("\"{$provider}\" is not a provider Ghostwriter can make images with.");
    }

    /**
     * Whether the model takes an effort setting:
     * - Anthropic: Opus, Sonnet and Fable 4.6 and later (not Haiku), as `output_config.effort`.
     * - OpenAI: the GPT-6 family, as `reasoning_effort`.
     * - Gemini: 2.5 and 3.x, as `thinkingConfig.thinkingLevel`.
     * - OpenRouter: the same models by their OpenRouter ids
     *   (`anthropic/claude-opus-5.5`, `openai/gpt-6.1-sol`,
     *   `google/gemini-3.8-flash`), as `reasoning.effort`. Other ids don't.
     */
    public static function takesEffort(string $provider, string $model): bool
    {
        if ($provider === 'openrouter') {
            $native = self::nativeModel($model);

            return $native !== null && self::takesEffort($native[0], $native[1]);
        }

        return match ($provider) {
            'anthropic' => self::anthropicVersion($model) >= [4, 6],
            'openai' => (bool) preg_match('/^gpt-6(?:[.-]|$)/', $model),
            'gemini' => (bool) preg_match('/^gemini-(?:2\.5|3(?:\.\d+)?)-/', $model),
            default => false,
        };
    }

    /**
     * Anthropic models that refuse a forced tool_choice (`any` or `tool`).
     * Dated snapshots match too.
     */
    public const ANTHROPIC_UNFORCED_TOOLS = ['claude-fable-5-1', 'claude-mythos-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5'];

    /**
     * How a model can be held to a reply's JSON Schema (TextRequest::$schema):
     *
     * - `json_schema` (TextResponse::JSON_SCHEMA): a JSON output mode.
     *   Anthropic's `output_config.format` on Opus, Sonnet and Fable 4.5
     *   and later, Mythos and Haiku 4.5; OpenAI's strict `response_format`
     *   on GPT-4o, GPT-4.1, GPT-5 and later and the o-series; Gemini's
     *   `responseJsonSchema` on 2.0 and later; and OpenRouter's
     *   `response_format` for the ids of those models.
     * - `tool` (TextResponse::TOOL): another Claude model, given a tool whose
     *   input is the reply.
     * - null: anything else; the request goes as plain text and its prompt
     *   has to ask for the shape. Also for an OpenRouter id from another
     *   maker, whose support varies by host.
     *
     * Checked against each provider's documentation on 2026-10-04.
     */
    public static function structuredOutput(string $provider, string $model): ?string
    {
        if ($provider === 'openrouter') {
            $native = self::nativeModel($model);

            return $native !== null && self::structuredOutput($native[0], $native[1]) === TextResponse::JSON_SCHEMA ? TextResponse::JSON_SCHEMA : null;
        }

        return match ($provider) {
            'anthropic' => match (true) {
                self::anthropicVersion($model) >= [4, 5], (bool) preg_match('/^claude-(?:haiku-4-5|mythos-)/', $model) => TextResponse::JSON_SCHEMA,
                str_starts_with($model, 'claude-') => TextResponse::TOOL,
                default => null,
            },
            'openai' => preg_match('/^(?:gpt-4o|gpt-4\.1|gpt-[5-9]|o[1-9])(?:[.-]|$)/', $model) ? TextResponse::JSON_SCHEMA : null,
            'gemini' => preg_match('/^gemini-(?:2\.\d+|[3-9](?:\.\d+)?)-/', $model) ? TextResponse::JSON_SCHEMA : null,
            'fake' => TextResponse::JSON_SCHEMA,
            default => null,
        };
    }

    /** Whether an Anthropic model refuses a forced tool_choice. */
    public static function refusesForcedTools(string $model): bool
    {
        return in_array((string) preg_replace('/-\d{8}$/', '', $model), self::ANTHROPIC_UNFORCED_TOOLS, true);
    }

    /**
     * Whether an Anthropic model takes server-side fallbacks: re-running a
     * request a safety classifier declines on the model Anthropic recommends.
     */
    public static function takesFallbacks(string $model): bool
    {
        $base = (string) preg_replace('/-\d{8}$/', '', $model);

        return in_array($base, self::ANTHROPIC_FALLBACK_MODELS, true);
    }

    /**
     * The provider and model an OpenRouter id stands for, in that
     * provider's own spelling: `anthropic/claude-opus-5.5` is anthropic's
     * `claude-opus-5-5`. A variant suffix (`:free`, `:batch`) is dropped.
     * Null for an id from anyone else.
     *
     * @return array{string, string}|null
     */
    public static function nativeModel(string $openRouterId): ?array
    {
        if (! preg_match('#^(anthropic|openai|google)/([^:]+)#', $openRouterId, $m)) {
            return null;
        }

        return match ($m[1]) {
            'anthropic' => ['anthropic', str_replace('.', '-', $m[2])],
            'openai' => ['openai', $m[2]],
            default => ['gemini', $m[2]],
        };
    }

    /**
     * [major, minor] of an Opus, Sonnet, Fable or Mythos model; [0, 0] for anything else.
     *
     * @return array{int, int}
     */
    private static function anthropicVersion(string $model): array
    {
        if (! preg_match('/^claude-(?:opus|sonnet|fable|mythos)-(\d{1,2})(?:-(\d{1,2}))?(?:-\d{8})?$/', $model, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0)];
    }
}
