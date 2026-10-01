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
    ];

    public const IMAGE_DEFAULTS = [
        'openai' => 'gpt-image-2.5-sunburst',
        'gemini' => 'gemini-3.1-flash-image',
    ];

    /** Providers that make images, in the order they are tried when none is chosen. */
    public const IMAGE_ORDER = ['openai', 'gemini'];

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
     */
    public static function takesEffort(string $provider, string $model): bool
    {
        return match ($provider) {
            'anthropic' => self::anthropicVersion($model) >= [4, 6],
            'openai' => (bool) preg_match('/^gpt-6(?:[.-]|$)/', $model),
            'gemini' => (bool) preg_match('/^gemini-(?:2\.5|3(?:\.\d+)?)-/', $model),
            default => false,
        };
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
     * [major, minor] of an Opus, Sonnet or Fable model; [0, 0] for anything else.
     *
     * @return array{int, int}
     */
    private static function anthropicVersion(string $model): array
    {
        if (! preg_match('/^claude-(?:opus|sonnet|fable)-(\d{1,2})(?:-(\d{1,2}))?(?:-\d{8})?$/', $model, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0)];
    }
}
