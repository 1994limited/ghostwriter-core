<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Something to tell an editor, as a key and its parameters: core never
 * returns sentences. Each addon translates the key with its own system
 * (Statamic `__()`, Craft `Craft.t('ghostwriter', …)`, Filament
 * `__('ghostwriter::gaps.*')`); the English source strings are in core's
 * `resources/lang/en/gaps.php`, with Laravel-style `:name` parameters.
 *
 *     new Message('gaps.ask', ['label' => 'Intro', 'hint' => 'adult ticket price'])
 */
final class Message
{
    /** @var array<string, string>|null */
    private static ?array $english = null;

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {}

    /**
     * The message in English, from core's source strings; the key itself
     * when there is no string for it.
     */
    public function english(): string
    {
        $strings = self::strings();
        $name = str_starts_with($this->key, 'gaps.') ? substr($this->key, 5) : $this->key;
        $text = $strings[$name] ?? $this->key;
        $params = $this->params;

        // Longest names first, so `:items` isn't taken for `:item`.
        uksort($params, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($params as $param => $value) {
            $text = str_replace(':'.$param, (string) $value, $text);
        }

        return $text;
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }

    /**
     * Core's English source strings, by key without the `gaps.` prefix:
     * what each addon's build copies into its own format.
     *
     * @return array<string, string>
     */
    public static function strings(): array
    {
        if (self::$english === null) {
            $strings = require self::stringsFile();
            self::$english = is_array($strings) ? array_filter($strings, 'is_string') : [];
        }

        return self::$english;
    }

    public static function stringsFile(): string
    {
        return dirname(__DIR__, 2).'/resources/lang/en/gaps.php';
    }
}
