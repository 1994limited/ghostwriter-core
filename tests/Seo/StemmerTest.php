<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\Stemmer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StemmerTest extends TestCase
{
    /**
     * Snowball's own outputs for these words.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function words(): array
    {
        return [
            'English' => ['en_GB', [
                'pruning' => 'prune', 'prune' => 'prune', 'planting' => 'plant', 'plants' => 'plant', 'seedheads' => 'seedhead',
                'gardens' => 'garden', 'meadows' => 'meadow', 'standing' => 'stand', 'maintenance' => 'mainten', 'hopping' => 'hop',
                'hoping' => 'hope', 'happiness' => 'happi', 'generously' => 'generous', 'relational' => 'relat', 'adjustment' => 'adjust',
                'communication' => 'communic', 'skies' => 'sky', 'news' => 'news', 'consultation' => 'consult', 'designer' => 'design',
            ]],
            'German' => ['de', [
                'gärten' => 'gart', 'garten' => 'gart', 'katzen' => 'katz', 'aufeinanderfolgenden' => 'aufeinanderfolg', 'häuser' => 'haus',
                'möglichkeiten' => 'moglich', 'blumen' => 'blum', 'gestaltung' => 'gestalt', 'samenstände' => 'samenstand', 'planung' => 'planung',
            ]],
            'French' => ['fr', [
                'jardins' => 'jardin', 'jardin' => 'jardin', 'maisons' => 'maison', 'rapidement' => 'rapid', 'plantes' => 'plant',
                'continuellement' => 'continuel', 'heureusement' => 'heureux', 'fleurs' => 'fleur', 'nationale' => 'national',
            ]],
            'Dutch' => ['nl', [
                'bloemen' => 'bloem', 'tuinen' => 'tuin', 'lichamelijk' => 'licham', 'gemakkelijk' => 'gemak', 'planten' => 'plant',
                'maaien' => 'maai', 'grassen' => 'grass',
            ]],
            'Spanish' => ['es', [
                'chicas' => 'chic', 'gatos' => 'gat', 'jardines' => 'jardin', 'jardín' => 'jardin', 'corriendo' => 'corr',
                'plantas' => 'plant', 'flores' => 'flor', 'rápidamente' => 'rapid', 'nacionalidad' => 'nacional', 'trabajando' => 'trabaj',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $words
     */
    #[DataProvider('words')]
    public function test_each_language_stems_as_snowball_does(string $locale, array $words): void
    {
        $stems = [];

        foreach (array_keys($words) as $word) {
            $stems[$word] = Stemmer::stem($word, $locale);
        }

        $this->assertSame($words, $stems);
    }

    public function test_a_language_without_a_stemmer_keeps_the_first_five_letters(): void
    {
        $this->assertSame('ogrod', Stemmer::stem('ogrodnictwo', 'pl'));
        $this->assertSame('pruni', Stemmer::stem('pruning'));
        $this->assertSame('en', Stemmer::language('en-GB'));
        $this->assertNull(Stemmer::language('pl_PL'));
    }

    public function test_words_it_cant_read_are_kept_whole(): void
    {
        $this->assertSame('a1b2', Stemmer::stem('a1b2', 'en'));
        $this->assertSame('ox', Stemmer::stem('ox', 'en'));
    }
}
