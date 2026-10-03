<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

/**
 * A model per tier of agent (Agents::WRITING, Agents::QUICK), for a
 * provider that offers several (OpenRouter). Optional: a ProviderSettings
 * that also implements this is asked first; one that doesn't gets
 * Models::OPENROUTER_TIERS, or its textModel() for every agent.
 */
interface ModelTiers
{
    /** The model for this tier, or null for the default. */
    public function tierModel(string $provider, string $tier): ?string;
}
