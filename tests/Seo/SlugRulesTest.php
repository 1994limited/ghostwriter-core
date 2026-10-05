<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\FilenameRules;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SlugRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Slugs and file names (SEO layer §10, §11, decision 13), in the five
 * languages core has phrase lists for.
 */
final class SlugRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function titles(): array
    {
        return [
            'a how-to keeps its whole phrase' => ['How to prune a walled garden in winter', 'en', 'how-to-prune-a-walled-garden-in-winter'],
            'a question keeps its whole phrase' => ['What to do in the garden in March', 'en', 'what-to-do-in-the-garden-in-march'],
            'filler off the start and the end' => ['The best roses for', 'en', 'best-roses'],
            'an article at the start' => ['The best roses for shade', 'en', 'best-roses-for-shade'],
            'en, a negation' => ['No-dig gardening for a new border', 'en', 'no-dig-gardening-for-a-new-border'],
            'de' => ['Wie man einen ummauerten Garten im Winter schneidet', 'de', 'wie-man-einen-ummauerten-garten-im-winter-schneidet'],
            'de, an article at the start' => ['Der Garten im März', 'de', 'garten-im-marz'],
            'fr' => ['Comment tailler un jardin clos en hiver', 'fr', 'comment-tailler-un-jardin-clos-en-hiver'],
            'fr, an article at the start' => ['Le jardin en mars', 'fr', 'jardin-en-mars'],
            'nl' => ['Hoe je een ommuurde tuin in de winter snoeit', 'nl', 'hoe-je-een-ommuurde-tuin-in-de-winter-snoeit'],
            'nl, an article at the start' => ['De tuin in maart', 'nl', 'tuin-in-maart'],
            'es' => ['Cómo podar un jardín amurallado en invierno', 'es', 'como-podar-un-jardin-amurallado-en-invierno'],
            'es, an article at the start' => ['El jardín en marzo', 'es', 'jardin-en-marzo'],
            'a long title loses its stop words, en' => ['How to prune a walled garden in winter without losing next year’s flowers', 'en', 'prune-walled-garden-winter-losing-next'],
            'a long title loses its stop words, de' => ['Wie man einen ummauerten Garten im Winter schneidet, ohne die Blüten zu verlieren', 'de', 'ummauerten-garten-winter-schneidet-bluten-verlieren'],
            'a long title loses its stop words, fr' => ['Comment tailler un jardin clos en hiver sans perdre les fleurs de l’année prochaine', 'fr', 'comment-tailler-jardin-clos-hiver-perdre'],
            'a long title loses its stop words, nl' => ['Hoe je een ommuurde tuin in de winter snoeit zonder de bloemen van volgend jaar te verliezen', 'nl', 'ommuurde-tuin-winter-snoeit-bloemen-volgend'],
            'a long title loses its stop words, es' => ['Cómo podar un jardín amurallado en invierno sin perder las flores del año que viene', 'es', 'podar-jardin-amurallado-invierno-perder-flores'],
            'a language with no list keeps its words' => ['Jak przycinać ogród zimą', 'pl', 'jak-przycinac-ogrod-zima'],
            'only stop words' => ['What we do', 'en', 'what-we-do'],
            'asks left out, checks as their value' => ['[[check: 3 areas | from: Durham, Tyne, Wear]] we cover in [[ask: year]]', 'en', '3-areas-we-cover'],
        ];
    }

    #[DataProvider('titles')]
    public function test_a_slug_from_a_title(string $title, string $language, string $expected): void
    {
        $this->assertSame($expected, SlugRules::suggest($title, $language));
    }

    public function test_years_stay_only_in_dated_groups_or_to_avoid_a_clash(): void
    {
        $this->assertSame('garden-jobs-for-late-february', SlugRules::suggest('Garden jobs for late February 2026', 'en'));
        $this->assertSame('garden-jobs-for-late-february-2026', SlugRules::suggest('Garden jobs for late February 2026', 'en', dated: true));
        $this->assertSame('garden-jobs-for-late-february-2026', SlugRules::suggest('Garden jobs for late February 2026', 'en', taken: ['garden-jobs-for-late-february']), 'Without the year it clashes.');
    }

    public function test_a_long_title_is_cut_to_six_words_and_sixty_characters_between_words(): void
    {
        $slug = SlugRules::suggest('Planting perennials, shrubs, climbers, bulbs, hedges and trees across Northumberland', 'en');

        $this->assertSame('planting-perennials-shrubs-climbers-bulbs-hedges', $slug);
        $long = SlugRules::suggest('Extraordinarily comprehensive establishment maintenance responsibilities', 'en');
        $this->assertLessThanOrEqual(SlugRules::MAX_LENGTH, strlen($long));
        $this->assertSame('short-title-under-sixty-characters-keeps-every-word-it-has', SlugRules::suggest('A short title under sixty characters keeps every word it has', 'en'), 'Exactly 60 characters: not long; only the article at the start goes.');
        $this->assertDoesNotMatchRegularExpression('/-$/', $long);
    }

    public function test_unique_in_its_scope(): void
    {
        $this->assertSame('winter-care-visits-2', SlugRules::suggest('Winter care visits', 'en', taken: ['winter-care-visits']));
        $this->assertSame('winter-care-visits-3', SlugRules::suggest('Winter care visits', 'en', taken: ['winter-care-visits', 'winter-care-visits-2']));
        $this->assertSame('', SlugRules::suggest('[[ask: title]]', 'en'));
        $this->assertSame('my-own-slug', SlugRules::clean(' My own slug! '));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function images(): array
    {
        return [
            'en' => ['A walled garden in winter, royalty free stock photo', 'en', 'walled-garden-winter'],
            'de' => ['Ein ummauerter Garten im Winter – Stockfoto', 'de', 'ummauerter-garten-winter'],
            'fr' => ['Photo de stock : un jardin clos en hiver', 'fr', 'jardin-clos-hiver'],
            'nl' => ['Een ommuurde tuin in de winter, stockfoto', 'nl', 'ommuurde-tuin-winter'],
            'es' => ['Foto de stock de un jardín amurallado en invierno', 'es', 'jardin-amurallado-invierno'],
            'six words at most' => ['Roses, lavender, salvias, alliums, grasses, sedums and asters in a border', 'en', 'roses-lavender-salvias-alliums-grasses-sedums'],
            'one word is no name' => ['Image of roses', 'en', ''],
            'numbers are no name' => ['IMG 2231', 'en', ''],
        ];
    }

    #[DataProvider('images')]
    public function test_a_file_name_from_an_images_words(string $text, string $language, string $expected): void
    {
        $this->assertSame($expected, FilenameRules::descriptive($text, $language));
    }

    public function test_the_first_text_that_names_it(): void
    {
        $this->assertSame('mulched-border-frost', FilenameRules::first([null, 'Stock photo', 'A mulched border in frost'], 'en'));
        $this->assertLessThanOrEqual(50, strlen(FilenameRules::descriptive(str_repeat('Extraordinarily ', 8), 'en')));
    }
}
