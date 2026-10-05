<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * The Connections page's words, from resources/lang/<language>/connections.php,
 * with English for anything a language doesn't have. Read at run time, so
 * the addons hand them to their page as they are (all()) rather than
 * copying them into their own translation files. Parameters are
 * Laravel-style (`:service`); the page fills them in the same way.
 *
 *     $strings = Strings::for('de-DE');
 *     $strings->get('status.connected', ['ending' => '••a1b2']);
 */
final class Strings
{
    /** The languages besides English. */
    public const TRANSLATED = ['de', 'fr', 'nl', 'es'];

    /** @var array<string, array<string, string>> */
    private static array $files = [];

    /**
     * @param  array<string, string>  $strings
     */
    private function __construct(private readonly array $strings, public readonly string $language) {}

    public static function english(): self
    {
        return new self(self::file('en'), 'en');
    }

    /** For a locale such as `de`, `de_DE` or `fr-CA`; English for any other. */
    public static function for(string $locale): self
    {
        $language = strtolower(substr($locale, 0, 2));

        if (! in_array($language, self::TRANSLATED, true)) {
            return self::english();
        }

        return new self(self::file($language) + self::file('en'), $language);
    }

    public function has(string $key): bool
    {
        return isset($this->strings[$key]);
    }

    /**
     * @param  array<string, string|int|float>  $params
     */
    public function get(string $key, array $params = []): string
    {
        return self::format($this->strings[$key] ?? $key, $params);
    }

    /**
     * Every string, by key, for a page to show.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->strings;
    }

    /**
     * Fills in `:name` parameters, longest names first so `:service` isn't
     * taken for `:serv`.
     *
     * @param  array<string, string|int|float>  $params
     */
    public static function format(string $text, array $params): string
    {
        uksort($params, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($params as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public static function path(string $language): string
    {
        return dirname(__DIR__, 2).'/resources/lang/'.$language.'/connections.php';
    }

    /**
     * @return array<string, string>
     */
    private static function file(string $language): array
    {
        if (! isset(self::$files[$language])) {
            $path = self::path($language);
            $strings = is_file($path) ? require $path : [];
            self::$files[$language] = is_array($strings) ? array_filter($strings, 'is_string') : [];
        }

        return self::$files[$language];
    }
}
