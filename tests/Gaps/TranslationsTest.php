<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SEO layer's strings ship in German, French, Dutch and Spanish
 * (decision 18): every SEO string is translated, each translation has an
 * English string, and keeps its parameters.
 */
final class TranslationsTest extends TestCase
{
    /** Which keys of each namespace are the SEO layer's. */
    private const SEO = [
        'seo' => '/./',
        'gaps' => '/^(seo-|links-added|heading-long|few-links|speech\.(seo-|links-added|heading-long|few-links)|step\.|fix\.(keep-link|use-text|add-links|skip))/',
        'suggest' => '/^(category\.seo|speech\.seo|finding\.(seo-|heading-long|few-links))/',
        'revisit' => '/^reason\.(seo-|few-links|heading-levels|competing|readability)/',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function languages(): array
    {
        return array_combine(Message::TRANSLATED, array_map(fn (string $language) => [$language], Message::TRANSLATED));
    }

    #[DataProvider('languages')]
    public function test_every_seo_string_is_translated_with_its_parameters(string $language): void
    {
        foreach (self::SEO as $namespace => $pattern) {
            $english = Message::strings($namespace);
            $translated = Message::translations($namespace, $language);

            foreach ($english as $key => $text) {
                if (preg_match($pattern, $key) === 1) {
                    $this->assertArrayHasKey($key, $translated, "{$language} {$namespace}.{$key}");
                }
            }

            foreach ($translated as $key => $text) {
                $this->assertArrayHasKey($key, $english, "{$language} {$namespace}.{$key} has no English string.");
                $this->assertSame(self::params($english[$key]), self::params($text), "{$language} {$namespace}.{$key} keeps its parameters.");
                $this->assertNotSame('', trim($text));
            }
        }
    }

    public function test_a_language_without_translations_has_none(): void
    {
        $this->assertSame([], Message::translations('seo', 'pl'));
        $this->assertNotSame([], Message::translations('seo', 'de_DE'));
    }

    /**
     * @return list<string>
     */
    private static function params(string $text): array
    {
        preg_match_all('/:([a-zA-Z][a-zA-Z_]*)/', $text, $matches);
        $params = array_values(array_unique($matches[1]));
        sort($params);

        return $params;
    }
}
