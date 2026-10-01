<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

/**
 * Where API keys come from. Each addon implements this over its own config
 * or environment helper.
 */
interface Credentials
{
    /**
     * The environment variable holding each service's key. Defined once here
     * so every addon's settings screen and docs name the same variables.
     */
    public const ENV = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai' => 'OPENAI_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'unsplash' => 'UNSPLASH_ACCESS_KEY',
        'pixabay' => 'PIXABAY_API_KEY',
        'pexels' => 'PEXELS_API_KEY',
    ];

    /**
     * The trimmed key, or null when it is unset or blank. Read every time;
     * never cached or stored.
     */
    public function key(string $provider): ?string;
}
