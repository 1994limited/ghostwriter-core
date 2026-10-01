<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * Per-agent defaults: how many tokens each kind of call may use, and how
 * hard it should think. A TextRequest can override both.
 *
 * Each limit is the most generous any addon used, because thinking models
 * count their reasoning against it. An agent not listed gets
 * DEFAULT_MAX_TOKENS and the provider's own effort.
 */
final class Agents
{
    public const DEFAULT_MAX_TOKENS = 16000;

    public const MAX_TOKENS = [
        'voice-analyst' => 16000,
        'voice-editor' => 16000,
        'type-analyst' => 16000,
        'writer' => 16000,
        'planner' => 16000,
        'kind-finder' => 8000,
        'brief-writer' => 6000,
        'imagery-analyst' => 6000,
        'photo-researcher' => 2000,
        'photo-picker' => 2000,
        'photo-scout' => 2000,
    ];

    /** Agents with an effort of their own; the rest leave it to the provider. */
    public const EFFORT = [
        'photo-researcher' => 'low',
        'photo-picker' => 'low',
        'photo-scout' => 'low',
    ];

    public static function maxTokens(string $agent): int
    {
        return self::MAX_TOKENS[$agent] ?? self::DEFAULT_MAX_TOKENS;
    }

    public static function effort(string $agent): ?Effort
    {
        return isset(self::EFFORT[$agent]) ? Effort::from(self::EFFORT[$agent]) : null;
    }
}
