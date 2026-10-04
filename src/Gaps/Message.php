<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Something to tell an editor, as a key and its parameters: core never
 * returns sentences. Each addon translates the key with its own system
 * (Statamic `__()`, Craft `Craft.t('ghostwriter', …)`, Filament
 * `__('ghostwriter::gaps.*')`); the English source strings are in core's
 * `resources/lang/en/{namespace}.php` (`gaps`, `suggest`, `revisit`), with
 * Laravel-style `:name` parameters.
 *
 *     new Message('gaps.ask', ['label' => 'Intro', 'hint' => 'adult ticket price'])
 */
final class Message
{
    /** @var array<string, array<string, string>> Source strings by namespace. */
    private static array $english = [];

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
        [$namespace, $name] = self::split($this->key);
        $text = self::strings($namespace)[$name] ?? $this->key;
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
     * Core's English source strings for a namespace (`gaps`, `suggest`,
     * `revisit`), by key without the prefix: what each addon's build copies
     * into its own format.
     *
     * @return array<string, string>
     */
    public static function strings(string $namespace = 'gaps'): array
    {
        if (! isset(self::$english[$namespace])) {
            $file = self::stringsFile($namespace);
            $strings = is_file($file) ? require $file : [];
            self::$english[$namespace] = is_array($strings) ? array_filter($strings, 'is_string') : [];
        }

        return self::$english[$namespace];
    }

    public static function stringsFile(string $namespace = 'gaps'): string
    {
        return dirname(__DIR__, 2).'/resources/lang/en/'.$namespace.'.php';
    }

    /**
     * A key's namespace and name: "suggest.speech.voice" is `suggest` and
     * "speech.voice". A key with no namespace core has strings for is a
     * `gaps` key, as it always was.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $key): array
    {
        $dot = strpos($key, '.');

        if ($dot !== false && preg_match('/^[a-z]+$/', substr($key, 0, $dot)) === 1 && is_file(self::stringsFile(substr($key, 0, $dot)))) {
            return [substr($key, 0, $dot), substr($key, $dot + 1)];
        }

        return ['gaps', $key];
    }
}
